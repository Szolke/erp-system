<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** @group Termékek */
class ProductController extends Controller
{
    use EnforcesCompanyScope;

    public function index(Request $request)
    {
        $this->authorize('product.view');

        $products = Product::query()
            ->with('vatRate')
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();
                $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%"));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request, AuditLogger $auditLogger)
    {
        $product = Product::create($request->validated())->refresh();

        $auditLogger->logChange(
            'product.create',
            $product->company_id,
            $request->user()->id,
            $product,
            [],
            $product->only($product->getFillable()),
        );

        return ProductResource::make(
            $product->load('vatRate')->loadMissing(['creator:id,name', 'updater:id,name'])
        )
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product)
    {
        $this->assertBelongsToCurrentCompany($product);
        $this->authorize('product.view');

        // Blame-adat (created_by/updated_by) csak az egy-rekordos válaszokban
        // jelenik meg — a listát ugyanez a Resource szolgálja ki, de ott a
        // reláció nincs betöltve, így a WithBlameable trait whenLoaded() kapuja
        // kihagyja a mezőket (nincs N+1). Az oszlop-korlátozás (:id,name)
        // megakadályozza, hogy felesleges/érzékeny user-mező töltődjön be.
        return ProductResource::make(
            $product->load('vatRate')->loadMissing(['creator:id,name', 'updater:id,name'])
        );
    }

    public function update(UpdateProductRequest $request, Product $product, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($product);

        $oldValues = $product->only($product->getFillable());
        $product->update($request->validated());
        $newValues = $product->fresh()->only($product->getFillable());

        $auditLogger->logChange('product.update', $product->company_id, $request->user()->id, $product, $oldValues, $newValues);

        return ProductResource::make(
            $product->load('vatRate')->loadMissing(['creator:id,name', 'updater:id,name'])
        );
    }

    public function destroy(Product $product, Request $request, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($product);
        $this->authorize('product.delete');

        $oldValues = $product->only($product->getFillable());
        $companyId = $product->company_id;
        $product->delete();

        $auditLogger->logChange('product.delete', $companyId, $request->user()->id, $product, $oldValues, []);

        return response()->noContent();
    }
}
