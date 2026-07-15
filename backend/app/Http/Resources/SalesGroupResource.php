<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SalesGroup */
class SalesGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $prefix = $this->company?->group_prefix;

        return [
            'id'           => $this->id,
            'company_id'   => $this->company_id,
            'name'         => $this->name,
            'display_name' => $prefix ? "{$prefix}_{$this->name}" : $this->name,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
