<?php

namespace App\Http\Requests;

use App\Enums\NavEnvironment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('company.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tax_number' => ['required', 'regex:/^\d{8}-\d-\d{2}$/'],
            'eu_tax_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:255'],
            'address_line' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'invoice_header_text' => ['nullable', 'string'],
            'invoice_footer_text' => ['nullable', 'string'],
            'base_currency' => ['required', 'string', 'size:3'],
            'is_active' => ['boolean'],
            'nav_environment' => ['required', Rule::enum(NavEnvironment::class)],
        ];
    }
}
