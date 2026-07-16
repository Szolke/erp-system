<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class StoreJobPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('job_position.manage');
    }

    public function rules(): array
    {
        // A célkör company_id-je: 'global' true esetén a globális halmaz
        // (NULL), egyébként az aktuális cég — ugyanaz a kör, amibe a
        // controller ténylegesen létrehozza a sort (lásd JobPositionController::store).
        $targetCompanyId = $this->boolean('global') ? null : app(CurrentCompany::class)->id();

        return [
            'global'     => ['sometimes', 'boolean'],
            'active'     => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'name'       => [
                'required', 'string', 'max:100',
                function ($attribute, $value, $fail) use ($targetCompanyId) {
                    // Postgres a NULL company_id-t sosem tekinti egyenlőnek NULL-lal,
                    // ezért app-szintű ellenőrzés kell (DB Rule::unique ezt nem fedné le).
                    $exists = DB::table('job_positions')
                        ->where('name', $value)
                        ->when(
                            $targetCompanyId === null,
                            fn ($q) => $q->whereNull('company_id'),
                            fn ($q) => $q->where('company_id', $targetCompanyId)
                        )
                        ->exists();

                    if ($exists) {
                        $fail('Ilyen nevű munkakör már létezik ebben a láthatósági körben.');
                    }
                },
            ],
        ];
    }
}
