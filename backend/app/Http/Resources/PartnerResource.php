<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Partner */
class PartnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'tax_number' => $this->tax_number,
            'eu_tax_number' => $this->eu_tax_number,
            'registration_number' => $this->registration_number,
            'billing_postal_code' => $this->billing_postal_code,
            'billing_city' => $this->billing_city,
            'billing_address_line' => $this->billing_address_line,
            'shipping_postal_code' => $this->shipping_postal_code,
            'shipping_city' => $this->shipping_city,
            'shipping_address_line' => $this->shipping_address_line,
            'default_payment_method_id' => $this->default_payment_method_id,
            'default_currency' => $this->default_currency,
            'email' => $this->email,
            'phone' => $this->phone,
            'bank_account_number' => $this->bank_account_number,
            'is_active'     => $this->is_active,
            'custom_fields' => $this->custom_fields ?? [],
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
