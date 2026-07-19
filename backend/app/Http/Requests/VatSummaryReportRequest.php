<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VatSummaryReportRequest extends FormRequest
{
    use ValidatesReportPeriod;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->periodRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->withPeriodValidator($validator);
    }
}
