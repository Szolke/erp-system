<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List/history view — NEVER includes request_xml/response_xml. See
 * NavSubmissionLogDetailResource for the single-entry detail view that does
 * (docs/nav-logging-audit.md: raw XML must never ride along in a list response).
 *
 * @mixin \App\Models\NavSubmissionLog
 */
class NavSubmissionLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'nav_status' => $this->invoice->nav_status,
            ]),
            'attempt_number' => $this->attempt_number,
            'operation' => $this->operation,
            'invoice_operation' => $this->invoice_operation,
            'environment' => $this->environment,
            'transaction_id' => $this->transaction_id,
            'status' => $this->status,
            'processing_result' => $this->processing_result,
            'validation_messages' => $this->validation_messages,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at,
        ];
    }
}
