<?php

namespace App\Http\Requests;

use App\Enums\AssetStatus;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('asset.create');
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            // 'name' is server-generated (AssetNumberGenerator) — deliberately not
            // accepted here, so a client-supplied 'name' is silently ignored.
            'serial_number' => [
                'required', 'string', 'max:255',
                Rule::unique('assets')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'imei' => [
                'nullable', 'string', 'max:255',
                Rule::unique('assets')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'asset_type_id' => [
                'required', 'integer',
                Rule::exists('asset_types', 'id')->where(
                    fn ($query) => $query->where(
                        fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)
                    )
                ),
            ],
            'status' => ['sometimes', Rule::enum(AssetStatus::class)],
        ];
    }
}
