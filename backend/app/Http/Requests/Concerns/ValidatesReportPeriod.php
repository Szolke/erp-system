<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Közös 'from'/'to' (YYYY-MM vagy YYYY-MM-DD) + 'date_basis' validáció a
 * riport-végpontokhoz — mindhárom időszak-alapú riport (invoices, products,
 * vat-summary) ugyanazt a bemeneti alakot fogadja.
 */
trait ValidatesReportPeriod
{
    protected function periodRules(): array
    {
        return [
            'from' => ['required', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
            'to' => ['required', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
            'date_basis' => ['nullable', Rule::in(['fulfillment', 'issue'])],
        ];
    }

    protected function withPeriodValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $from = $this->input('from');
            $to = $this->input('to');

            if (! $from || ! $to || $validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            try {
                $fromDate = Carbon::parse(strlen($from) === 7 ? $from.'-01' : $from);
                $toDate = Carbon::parse(strlen($to) === 7 ? $to.'-01' : $to);
            } catch (\Throwable) {
                $validator->errors()->add('from', 'Érvénytelen dátum formátum.');

                return;
            }

            if ($fromDate->gt($toDate)) {
                $validator->errors()->add('from', 'A "from" nem lehet később, mint a "to".');
            }
        });
    }
}
