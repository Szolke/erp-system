<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivablesAgingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'partner_id' => ['nullable', 'integer', Rule::exists('partners', 'id')->where('company_id', $companyId)],
        ];
    }
}
