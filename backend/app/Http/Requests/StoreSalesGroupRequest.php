<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class StoreSalesGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sales_group.create');
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'name' => [
                'required', 'string', 'max:100',
                function ($attribute, $value, $fail) use ($companyId) {
                    $exists = DB::table('sales_groups')
                        ->where('company_id', $companyId)
                        ->whereRaw('LOWER(name) = LOWER(?)', [$value])
                        ->exists();
                    if ($exists) {
                        $fail('Ilyen nevű csoport már létezik (kis- és nagybetű-független egyediség).');
                    }
                },
            ],
        ];
    }
}
