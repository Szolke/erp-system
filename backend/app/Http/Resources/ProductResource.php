<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'type' => $this->type,
            'vat_rate_id' => $this->vat_rate_id,
            'vat_rate' => $this->whenLoaded('vatRate', fn () => [
                'id' => $this->vatRate->id,
                'name' => $this->vatRate->name,
                'nav_code' => $this->vatRate->nav_code,
            ]),
            'base_price' => $this->base_price,
            'base_currency' => $this->base_currency,
            'is_active'     => $this->is_active,
            'custom_fields' => $this->custom_fields ?? [],
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
