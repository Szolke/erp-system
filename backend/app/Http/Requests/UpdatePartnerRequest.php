<?php

namespace App\Http\Requests;

use App\Enums\PartnerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('partner.edit');
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PartnerType::class)],
            'name' => ['required', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'eu_tax_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'billing_postal_code' => ['required', 'string', 'max:10'],
            'billing_city' => ['required', 'string', 'max:255'],
            'billing_address_line' => ['required', 'string', 'max:255'],
            'shipping_postal_code' => ['nullable', 'string', 'max:10'],
            'shipping_city' => ['nullable', 'string', 'max:255'],
            'shipping_address_line' => ['nullable', 'string', 'max:255'],
            'default_payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')],
            'default_currency' => ['required', 'string', 'size:3'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
