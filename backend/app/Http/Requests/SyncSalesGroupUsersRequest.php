<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tagság-szinkron az értékesítő csoporton. Ez a scope-leak őre az írás oldalon.
 *
 * A users táblán NINCS company_id — a cég-tagság a company_user pivoton él,
 * és egy felhasználó több céghez is tartozhat. Ezért a hovatartozást nem az
 * users, hanem a company_user táblán ellenőrizzük: a szabály egyszerre
 * igazolja, hogy a user létezik ÉS hogy tagja az aktuális cégnek. Idegen cég
 * user_id-ja így 422-vel elszáll, nem csendben bekerül a pivotba.
 */
class SyncSalesGroupUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Külön member-jogkulcs szándékosan nincs: a tagság a csoport
        // szerkesztésének része (l. docs/progress.md — sales group 2. fázis).
        return $this->user()->can('sales_group.edit');
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            // 'present' (nem 'required'): az üres tömb érvényes kérés — az
            // összes tag leválasztását jelenti.
            'user_ids'   => ['present', 'array'],
            'user_ids.*' => [
                'integer',
                Rule::exists('company_user', 'user_id')->where('company_id', $companyId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_ids.*.exists' => 'A kiválasztott felhasználó nem tagja ennek a cégnek.',
        ];
    }
}
