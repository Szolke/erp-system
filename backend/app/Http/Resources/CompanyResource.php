<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tax_number' => $this->tax_number,
            'eu_tax_number' => $this->eu_tax_number,
            'registration_number' => $this->registration_number,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'address_line' => $this->address_line,
            'country_code' => $this->country_code,
            'email' => $this->email,
            'phone' => $this->phone,
            'invoice_header_text' => $this->invoice_header_text,
            'invoice_footer_text' => $this->invoice_footer_text,
            'base_currency' => $this->base_currency,
            'is_active' => $this->is_active,
            'nav_environment' => $this->nav_environment,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
