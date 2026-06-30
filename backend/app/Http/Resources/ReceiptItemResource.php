<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ReceiptItem */
class ReceiptItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'vat_rate_id' => $this->vat_rate_id,
            'vat_rate' => $this->whenLoaded('vatRate', fn () => [
                'id' => $this->vatRate->id,
                'name' => $this->vatRate->name,
                'rate_percent' => $this->vatRate->rate_percent,
            ]),
            'net_amount' => $this->net_amount,
            'vat_amount' => $this->vat_amount,
            'gross_amount' => $this->gross_amount,
            'sort_order' => $this->sort_order,
        ];
    }
}
