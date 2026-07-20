<?php

namespace App\Http\Requests;

use App\Enums\EnyugtaMode;
use App\Models\CompanyEnyugtaCredential;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnyugtaSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('enyugta.manage');
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();
        $exists = CompanyEnyugtaCredential::where('company_id', $companyId)->exists();

        // Új rekordnál minden titkos mező kötelező; meglévőnél opcionális
        // (üres = megtartja a tárolt titkosított értéket) — a
        // CompanyNavCredentialController::upsert() bevált mintáját követi.
        $secretRule = $exists ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'max:255'];

        return [
            'login' => $secretRule,
            'password' => $secretRule,
            'signing_key' => $secretRule,
            'exchange_key' => $secretRule,
            // Bare 8 jegyű törzsszám — ugyanaz a formátum, mint a
            // company_nav_credentials.nav_tax_number-nél (a785209 javítás óta).
            'tax_number' => ['required', 'regex:/^\d{8}$/'],
            'mode' => ['required', Rule::enum(EnyugtaMode::class)],
            'base_url_override' => ['nullable', 'string', 'max:255', 'url'],
            'send_empty_reports' => ['required', 'boolean'],
        ];
    }
}
