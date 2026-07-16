<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('asset.create');
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            // 'company_id' is deliberately not accepted here — the controller sets it
            // explicitly from CurrentCompany. A client-supplied company_id (or its
            // absence) must never determine whether a type becomes global or company-
            // owned (see docs/progress.md, assets module step 3 open point).
            'code' => [
                'required', 'string', 'max:255',
                Rule::unique('asset_types')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
