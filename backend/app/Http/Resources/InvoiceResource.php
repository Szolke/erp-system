<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'partner_id' => $this->partner_id,
            'partner' => $this->whenLoaded('partner', fn () => [
                'id' => $this->partner->id,
                'name' => $this->partner->name,
                'tax_number' => $this->partner->tax_number,
                'billing_postal_code' => $this->partner->billing_postal_code,
                'billing_city' => $this->partner->billing_city,
                'billing_address_line' => $this->partner->billing_address_line,
            ]),
            'issue_date' => $this->issue_date?->format('Y-m-d'),
            'fulfillment_date' => $this->fulfillment_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'exchange_rate_date' => $this->exchange_rate_date?->format('Y-m-d'),
            'payment_method_id' => $this->payment_method_id,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => [
                'id' => $this->paymentMethod->id,
                'code' => $this->paymentMethod->code,
                'name' => $this->paymentMethod->name,
            ]),
            'net_total' => $this->net_total,
            'vat_total' => $this->vat_total,
            'gross_total' => $this->gross_total,
            'gross_total_base_currency' => $this->gross_total_base_currency,
            'storno_of_invoice_id' => $this->storno_of_invoice_id,
            'nav_status' => $this->nav_status,
            'notes' => $this->notes,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
