<?php

namespace App\Http\Requests;

use App\Models\SalesGroup;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class UpdateSalesGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sales_group.edit');
    }

    public function rules(): array
    {
        $companyId    = app(CurrentCompany::class)->id();
        $routeModel   = $this->route('sales_group');
        $salesGroupId = $routeModel instanceof SalesGroup ? $routeModel->id : (int) $routeModel;

        return [
            'name' => [
                'required', 'string', 'max:100',
                function ($attribute, $value, $fail) use ($companyId, $salesGroupId) {
                    $exists = DB::table('sales_groups')
                        ->where('company_id', $companyId)
                        ->whereRaw('LOWER(name) = LOWER(?)', [$value])
                        ->where('id', '!=', $salesGroupId)
                        ->exists();
                    if ($exists) {
                        $fail('Ilyen nevű csoport már létezik (kis- és nagybetű-független egyediség).');
                    }
                },
            ],
        ];
    }
}
