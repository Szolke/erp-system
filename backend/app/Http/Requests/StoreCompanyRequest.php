<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_superadmin;
    }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:255'],
            'tax_number'          => ['required', 'regex:/^\d{8}-\d-\d{2}$/'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'postal_code'         => ['nullable', 'string', 'max:10'],
            'city'                => ['nullable', 'string', 'max:255'],
            'address_line'        => ['nullable', 'string', 'max:255'],
            'country_code'        => ['nullable', 'string', 'size:2'],
            'email'               => ['nullable', 'email', 'max:255'],
            'phone'               => ['nullable', 'string', 'max:255'],
            'base_currency'       => ['nullable', 'string', 'size:3'],
        ];
    }
}
