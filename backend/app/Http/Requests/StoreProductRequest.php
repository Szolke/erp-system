<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('product.create');
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'sku' => [
                'required', 'string', 'max:255',
                Rule::unique('products')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(\App\Enums\ProductType::class)],
            'vat_rate_id' => ['required', 'integer', Rule::exists('vat_rates', 'id')],
            'base_price' => ['required', 'numeric', 'min:0'],
            'base_currency' => ['required', 'string', 'size:3'],
            'is_active'     => ['boolean'],
            'custom_fields' => ['nullable', 'array'],
        ];
    }
}
