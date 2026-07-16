<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Asset */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'serial_number' => $this->serial_number,
            'imei' => $this->imei,
            'asset_type_id' => $this->asset_type_id,
            'asset_type' => $this->whenLoaded('assetType', fn () => [
                'id' => $this->assetType->id,
                'code' => $this->assetType->code,
                'name' => $this->assetType->name,
            ]),
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
