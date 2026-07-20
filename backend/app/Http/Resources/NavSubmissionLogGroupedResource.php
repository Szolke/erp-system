<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row = one AFFECTED INVOICE, not one log row (see NavSubmissionLogController::index()).
 * $this is the invoice's LATEST NavSubmissionLog row within the current filter —
 * 'attempt_count' is a runtime-only attribute assigned by the controller, not a
 * database column. Never includes request_xml/response_xml, same rule as
 * NavSubmissionLogResource.
 *
 * @mixin \App\Models\NavSubmissionLog
 */
class NavSubmissionLogGroupedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'nav_status' => $this->invoice->nav_status,
                'partner_name' => $this->invoice->partner?->name,
            ]),
            'attempt_count' => $this->attempt_count,
            'latest' => [
                'id' => $this->id,
                'created_at' => $this->created_at,
                'operation' => $this->operation,
                'status' => $this->status,
                'processing_result' => $this->processing_result,
                'transaction_id' => $this->transaction_id,
            ],
        ];
    }
}
