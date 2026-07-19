<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportPeriod;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Csak paraméter-validáció — a jogosultság-ellenőrzés (report.view vs.
 * report.export) a ReportController-ben, explicit $this->authorize()
 * hívással történik, mert ugyanezt a kérés-osztályt a JSON-végpont ÉS a
 * CSV-export is használja, két különböző joggal.
 */
class InvoiceReportRequest extends FormRequest
{
    use ValidatesReportPeriod;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return array_merge($this->periodRules(), [
            'granularity' => ['nullable', Rule::in(['month', 'day'])],
            'partner_id' => ['nullable', 'integer', Rule::exists('partners', 'id')->where('company_id', $companyId)],
            'status' => ['nullable', Rule::in(['open', 'partial', 'paid'])],
            'include_receipts' => ['nullable', 'boolean'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $this->withPeriodValidator($validator);

        $validator->after(function (Validator $validator) {
            $from = $this->input('from');
            $to = $this->input('to');
            if (! $from || ! $to || $validator->errors()->isNotEmpty()) {
                return;
            }

            $granularity = $this->input('granularity', 'month');

            $fromDate = Carbon::parse(strlen($from) === 7 ? $from.'-01' : $from);
            $toDate = Carbon::parse(strlen($to) === 7 ? $to.'-01' : $to);
            $spanDays = $fromDate->diffInDays($toDate);

            if ($granularity === 'day' && $spanDays > 366) {
                $validator->errors()->add('to', 'Napi bontásnál a tartomány legfeljebb 366 nap lehet.');
            }
            if ($granularity === 'month' && $spanDays > 1830) {
                $validator->errors()->add('to', 'Havi bontásnál a tartomány legfeljebb 60 hónap lehet.');
            }
        });
    }

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);
        $data['include_receipts'] = $this->boolean('include_receipts');

        return $data;
    }
}
