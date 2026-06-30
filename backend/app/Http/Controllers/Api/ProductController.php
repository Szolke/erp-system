<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
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
            ->paginate(20);

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        $product = Product::create($request->validated())->refresh();

        return ProductResource::make($product->load('vatRate'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product)
    {
        $this->authorize('product.view');

        return ProductResource::make($product->load('vatRate'));
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $product->update($request->validated());

        return ProductResource::make($product->load('vatRate'));
    }

    public function destroy(Product $product)
    {
        $this->authorize('product.delete');

        $product->delete();

        return response()->noContent();
    }
}
