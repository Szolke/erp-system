<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoice.create');
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();
        $baseCurrency = Company::query()->find($companyId)?->base_currency;

        return [
            'partner_id' => ['required', 'integer',
                Rule::exists('partners', 'id')->where('company_id', $companyId)],
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')],
            'issue_date' => ['required', 'date'],
            'fulfillment_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => [
                Rule::requiredIf(fn () => $this->input('currency') !== $baseCurrency),
                'nullable', 'numeric', 'min:0.000001',
            ],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['required', 'string', 'max:255'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.vat_rate_id' => ['required', 'integer', Rule::exists('vat_rates', 'id')],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
