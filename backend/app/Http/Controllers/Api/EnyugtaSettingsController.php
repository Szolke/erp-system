<?php

namespace App\Http\Controllers\Api;

use App\Enums\EnyugtaMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\CopyEnyugtaFromNavRequest;
use App\Http\Requests\UpdateEnyugtaSettingsRequest;
use App\Models\Company;
use App\Models\CompanyEnyugtaCredential;
use App\Models\CompanyNavCredential;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;

/**
 * NAV eNyugta beállítások — 1. fázis: csak hitelesítő adatok + üzemmód
 * tárolása, NINCS beküldés (l. docs/progress.md fázis 1 szakasz).
 *
 * @group NAV eNyugta
 */
class EnyugtaSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * GET /api/settings/enyugta — a jelenlegi cég eNyugta beállításai.
     * A titkos mezők (login, password, signing_key, exchange_key) SOHA nem
     * kerülnek vissza — csak has_* boolean jelzi, hogy ki vannak-e töltve
     * (a CompanyNavCredentialController::toPublic() mintáját követi).
     */
    public function show(CurrentCompany $currentCompany): JsonResponse
    {
        $this->authorize('enyugta.view');

        $credential = CompanyEnyugtaCredential::where('company_id', $currentCompany->id())->first();

        return response()->json(['data' => $this->toPublic($credential)]);
    }

    /**
     * PUT /api/settings/enyugta — mentés (create vagy update, cégenként egy sor).
     * A jogosultság-ellenőrzés a FormRequest::authorize()-ban történik.
     */
    public function update(UpdateEnyugtaSettingsRequest $request, CurrentCompany $currentCompany): JsonResponse
    {
        $data = $request->validated();

        $credential = CompanyEnyugtaCredential::firstOrNew(['company_id' => $currentCompany->id()]);
        $isNew = ! $credential->exists;

        // Csak a ténylegesen beküldött titkos mezők íródnak felül — üres érték
        // update-nél a tárolt titkosított adatot változatlanul hagyja.
        $changedSecretFields = [];
        foreach (['login', 'password', 'signing_key', 'exchange_key'] as $field) {
            if (! empty($data[$field])) {
                $changedSecretFields[] = $field;
                $credential->{$field} = $data[$field];
            }
        }

        $credential->tax_number = $data['tax_number'];
        $credential->mode = $data['mode'];
        $credential->base_url_override = $data['base_url_override'] ?? null;
        $credential->send_empty_reports = $data['send_empty_reports'];
        $credential->save();

        $newValues = [
            'tax_number' => $data['tax_number'],
            'mode' => $data['mode'],
            'send_empty_reports' => $data['send_empty_reports'],
        ];
        if (! empty($changedSecretFields)) {
            // Csak a mezőNEVEK kerülnek naplózásra, az ÉRTÉKEK soha.
            $newValues['changed_secret_fields'] = $changedSecretFields;
        }

        $this->auditLogger->log(
            action: $isNew ? 'enyugta_credential.created' : 'enyugta_credential.updated',
            companyId: $currentCompany->id(),
            userId: $request->user()->id,
            auditable: $credential,
            oldValues: null,
            newValues: $newValues,
        );

        return response()->json(['data' => $this->toPublic($credential->fresh())]);
    }

    /**
     * POST /api/settings/enyugta/copy-from-nav — a company_nav_credentials
     * meglévő (test vagy production) sorából másolja át a login/password/
     * signing_key/exchange_key/tax_number mezőket.
     *
     * NEM tudjuk biztosan, hogy a technikai felhasználó ténylegesen
     * megosztható-e az Online Számla és az eNyugta interfész között (l.
     * docs/nav-enyugta-spec-jegyzetek.md 2. fejezet) — ez a végpont kényelmi
     * gyorsindítás, nem a helyesség garanciája; valós NAV-teszteléskor
     * derülhet ki, hogy külön technikai felhasználó szükséges.
     */
    public function copyFromNav(CopyEnyugtaFromNavRequest $request, CurrentCompany $currentCompany): JsonResponse
    {
        $company = Company::withoutGlobalScope('company')->findOrFail($currentCompany->id());
        $environment = $request->validated('environment') ?? $company->nav_environment?->value;

        if ($environment === null) {
            return response()->json([
                'message' => 'Nincs megadva és a cégnél sincs beállítva NAV environment, amiből másolni lehetne.',
            ], 422);
        }

        $source = CompanyNavCredential::where('company_id', $currentCompany->id())
            ->where('environment', $environment)
            ->first();

        if ($source === null) {
            return response()->json([
                'message' => sprintf(
                    'Nincs "%s" environment-hez tartozó NAV hitelesítő adat, amiből másolni lehetne.',
                    $environment,
                ),
            ], 422);
        }

        $credential = CompanyEnyugtaCredential::firstOrNew(['company_id' => $currentCompany->id()]);
        $isNew = ! $credential->exists;

        $credential->login = $source->nav_login;
        $credential->password = $source->nav_password;
        $credential->signing_key = $source->nav_signing_key;
        $credential->exchange_key = $source->nav_exchange_key;
        $credential->tax_number = $source->nav_tax_number;

        if ($isNew) {
            $credential->mode = EnyugtaMode::Mock;
            $credential->send_empty_reports = false;
        }

        $credential->save();

        $this->auditLogger->log(
            action: 'enyugta_credential.copied_from_nav',
            companyId: $currentCompany->id(),
            userId: $request->user()->id,
            auditable: $credential,
            oldValues: null,
            newValues: [
                'source_environment' => $environment,
                'changed_secret_fields' => ['login', 'password', 'signing_key', 'exchange_key', 'tax_number'],
            ],
        );

        return response()->json(['data' => $this->toPublic($credential->fresh())]);
    }

    private function toPublic(?CompanyEnyugtaCredential $c): array
    {
        if ($c === null) {
            return [
                'mode' => null,
                'base_url_override' => null,
                'send_empty_reports' => false,
                'last_verified_at' => null,
                'tax_number' => null,
                'has_login' => false,
                'has_password' => false,
                'has_signing_key' => false,
                'has_exchange_key' => false,
            ];
        }

        return [
            'mode' => $c->mode,
            'base_url_override' => $c->base_url_override,
            'send_empty_reports' => $c->send_empty_reports,
            'last_verified_at' => $c->last_verified_at,
            'tax_number' => $c->tax_number,
            'has_login' => ! empty($c->login),
            'has_password' => ! empty($c->password),
            'has_signing_key' => ! empty($c->signing_key),
            'has_exchange_key' => ! empty($c->exchange_key),
        ];
    }
}
