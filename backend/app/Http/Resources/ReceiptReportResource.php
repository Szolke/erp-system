<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ReceiptReport */
class ReceiptReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_date' => $this->report_date?->format('Y-m-d'),
            'type' => $this->type,
            'original_report_id' => $this->original_report_id,
            'status' => $this->status,
            'receipt_count' => $this->receipt_count,
            'total_net' => $this->total_net,
            'total_vat' => $this->total_vat,
            'total_gross' => $this->total_gross,
            'transaction_id' => $this->transaction_id,
            'submitted_at' => $this->submitted_at,
            'error_message' => $this->error_message,
            'retry_count' => $this->retry_count,
            'generated_at' => $this->generated_at,
            'lines' => ReceiptReportLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
