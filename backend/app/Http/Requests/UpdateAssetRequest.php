<?php

namespace App\Http\Requests;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('asset.edit');
    }

    public function rules(): array
    {
        $companyId  = app(CurrentCompany::class)->id();
        $routeModel = $this->route('asset');
        $assetId    = $routeModel instanceof Asset ? $routeModel->id : (int) $routeModel;

        return [
            // 'name' and 'asset_type_id' are deliberately not accepted here —
            // the name is server-generated and encodes the type's code, so
            // changing the type after the fact would make it misleading.
            'serial_number' => [
                'required', 'string', 'max:255',
                Rule::unique('assets')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($assetId),
            ],
            'imei' => [
                'nullable', 'string', 'max:255',
                Rule::unique('assets')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($assetId),
            ],
            'status' => ['required', Rule::enum(AssetStatus::class)],
        ];
    }
}
