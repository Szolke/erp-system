<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Single-entry detail — the ONLY resource exposing request_xml/response_xml.
 * request_xml never contains the NAV auth envelope (login/passwordHash/
 * requestSignature) — see NavXmlBuilder and NavTransactionStatusChecker, both
 * verified to never source it from Reporter::getLastRequestData(). response_xml
 * is NAV's own reply, also credential-free.
 *
 * @mixin \App\Models\NavSubmissionLog
 */
class NavSubmissionLogDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...(new NavSubmissionLogResource($this->resource))->toArray($request),
            'request_xml' => $this->request_xml,
            'response_xml' => $this->response_xml,
        ];
    }
}
