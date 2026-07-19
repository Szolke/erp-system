<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductReportRequest extends FormRequest
{
    use ValidatesReportPeriod;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->periodRules(), [
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'order_by' => ['nullable', Rule::in(['revenue', 'quantity'])],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $this->withPeriodValidator($validator);
    }
}
