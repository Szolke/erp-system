<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Support\ListSort;
use Illuminate\Http\Request;

/** @group Termékek */
class ProductController extends Controller
{
    use EnforcesCompanyScope;

    /**
     * Rendezhető oszlopok (l. App\Support\ListSort). A `vat_rate` a
     * kapcsolt `vat_rates.name`-re rendez, ezért a lekérdezés MINDIG joinolja
     * a vat_rates táblát — ugyanaz a minta, mint a DocumentController union
     * ágaiban a partners join, hogy a rendezés ne igényeljen feltételes
     * join-építést.
     */
    private const SORTABLE_COLUMNS = [
        'sku'        => 'products.sku',
        'name'       => 'products.name',
        'unit'       => 'products.unit',
        'base_price' => 'products.base_price',
        'type'       => 'products.type',
        'vat_rate'   => 'vat_rates.name',
    ];

    private const DEFAULT_SORT_KEY = 'name';

    private const SORT_TIE_BREAKERS = ['products.id ASC'];

    public function index(Request $request)
    {
        $this->authorize('product.view');

        $products = Product::query()
            ->leftJoin('vat_rates', 'vat_rates.id', '=', 'products.vat_rate_id')
            ->select('products.*')
            ->with('vatRate')
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();
                $query->where(fn ($q) => $q->where('products.name', 'ilike', "%{$search}%")
                    ->orWhere('products.sku', 'ilike', "%{$search}%"));
            })
            ->orderByRaw($this->orderBySql($request))
            ->paginate($this->perPage($request));

        return ProductResource::collection($products);
    }

    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY, 'asc')
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
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
