<?php

namespace App\Http\Controllers\Api;

use App\Enums\NavEnvironment;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyNavCredential;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** @group NAV Online Számla */
class CompanyNavCredentialController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * List NAV credentials for the active company, along with the active environment.
     * Secret fields (nav_login, nav_password, nav_signing_key, nav_exchange_key) are
     * never returned — only their presence is indicated via has_* boolean flags.
     */
    public function index(CurrentCompany $currentCompany): JsonResponse
    {
        $this->authorize('company.manage');

        $company = Company::findOrFail($currentCompany->id());

        $credentials = CompanyNavCredential::where('company_id', $currentCompany->id())
            ->orderBy('environment')
            ->get()
            ->map(fn ($c) => $this->toPublic($c));

        return response()->json([
            'active_environment' => $company->nav_environment,
            'credentials'        => $credentials,
        ]);
    }

    /**
     * Create or update the NAV credential for a given environment (test|production).
     * Secret fields are only overwritten when a non-empty value is sent;
     * on a new record all four secret fields are required.
     */
    public function upsert(Request $request, CurrentCompany $currentCompany, string $environment): JsonResponse
    {
        $this->authorize('company.manage');

        $env = NavEnvironment::tryFrom($environment);
        if ($env === null) {
            return response()->json(['message' => 'Érvénytelen environment. Lehetséges értékek: test, production.'], 422);
        }

        $cred = CompanyNavCredential::firstOrNew([
            'company_id'  => $currentCompany->id(),
            'environment' => $env,
        ]);

        // New record: all four secret fields required. Update: optional (empty = keep stored value).
        $secretRule = $cred->exists
            ? ['nullable', 'string', 'max:255']
            : ['required', 'string', 'max:255'];

        $data = $request->validate([
            'nav_tax_number'   => ['required', 'string', 'max:13'],
            'nav_login'        => $secretRule,
            'nav_password'     => $secretRule,
            'nav_signing_key'  => $secretRule,
            'nav_exchange_key' => $secretRule,
            'is_active'        => ['required', 'boolean'],
        ]);

        // Az aktív environment hitelesítőjét nem szabad kikapcsolni — a számlák
        // némán nem mennének ki (a job where('is_active', true) miatt). Előbb
        // kelljen környezetet váltani, különben a védelem megkerülhető PUT-on át.
        if (! $data['is_active']) {
            $company = Company::findOrFail($currentCompany->id());
            if ($company->nav_environment === $env) {
                return response()->json([
                    'message' => sprintf(
                        'A(z) "%s" jelenleg az aktív beküldési környezet — a hitelesítő nem kapcsolható ki. '
                        . 'Előbb válts másik környezetre.',
                        $env->value
                    ),
                ], 422);
            }
        }

        $isNew = ! $cred->exists;

        // Collect which secret fields will change — for audit log only, values are NEVER logged.
        $changedSecretFields = [];
        foreach (['nav_login', 'nav_password', 'nav_signing_key', 'nav_exchange_key'] as $field) {
            if (! empty($data[$field])) {
                $changedSecretFields[] = $field;
                $cred->$field = $data[$field];
            }
            // Empty on update → existing encrypted value in DB is kept as-is.
        }

        $cred->nav_tax_number = $data['nav_tax_number'];
        $cred->is_active      = $data['is_active'];
        $cred->save();

        $newValues = [
            'environment'    => $cred->environment->value,
            'nav_tax_number' => $data['nav_tax_number'],
            'is_active'      => $data['is_active'],
        ];
        if (! empty($changedSecretFields)) {
            $newValues['changed_secret_fields'] = $changedSecretFields;
        }

        $this->auditLogger->log(
            action:     $isNew ? 'nav_credential.created' : 'nav_credential.updated',
            companyId:  $currentCompany->id(),
            userId:     $request->user()->id,
            auditable:  $cred,
            oldValues:  null,
            newValues:  $newValues,
        );

        return response()->json(['data' => $this->toPublic($cred)]);
    }

    /**
     * Delete the NAV credential for a given environment.
     * Blocked when the target environment is currently the active one:
     * deleting it would leave the job without a usable credential (silent non-send).
     * Switch to the other environment first, then delete.
     */
    public function destroy(Request $request, CurrentCompany $currentCompany, string $environment): JsonResponse
    {
        $this->authorize('company.manage');

        $env = NavEnvironment::tryFrom($environment);
        if ($env === null) {
            return response()->json(['message' => 'Érvénytelen environment. Lehetséges értékek: test, production.'], 422);
        }

        $company = Company::findOrFail($currentCompany->id());

        if ($company->nav_environment === $env) {
            return response()->json([
                'message' => sprintf(
                    'A(z) "%s" environment jelenleg aktív (beküldési környezet). '
                    . 'Törlés előtt válts másik környezetre.',
                    $env->value
                ),
            ], 422);
        }

        $cred = CompanyNavCredential::where('company_id', $currentCompany->id())
            ->where('environment', $env)
            ->first();

        if ($cred) {
            $taxNumber = $cred->nav_tax_number;
            $cred->delete();

            $this->auditLogger->log(
                action:    'nav_credential.deleted',
                companyId: $currentCompany->id(),
                userId:    $request->user()->id,
                auditable: null,
                oldValues: ['environment' => $env->value, 'nav_tax_number' => $taxNumber],
                newValues: null,
            );
        }

        return response()->json(['message' => 'NAV credential törölve.']);
    }

    /**
     * Switch the active NAV environment for the company.
     * Returns 422 if the target environment has no is_active=true credential
     * (mirrors the job's where('is_active', true) guard — prevents silent NAV silence).
     */
    public function setActiveEnvironment(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        $this->authorize('company.manage');

        $data = $request->validate([
            'environment' => ['required', Rule::enum(NavEnvironment::class)],
        ]);

        $env = NavEnvironment::from($data['environment']);

        $hasActiveCredential = CompanyNavCredential::where('company_id', $currentCompany->id())
            ->where('environment', $env)
            ->where('is_active', true)
            ->exists();

        if (! $hasActiveCredential) {
            return response()->json([
                'message' => sprintf(
                    'A(z) "%s" environment-hez nincs aktív NAV hitelesítő beállítva. '
                    . 'Kérjük, először töltsd ki és aktiváld a hitelesítő adatokat.',
                    $env->value
                ),
            ], 422);
        }

        $company = Company::findOrFail($currentCompany->id());
        $oldEnv  = $company->nav_environment;
        $company->update(['nav_environment' => $env]);

        $this->auditLogger->log(
            action:    'nav_environment.changed',
            companyId: $currentCompany->id(),
            userId:    $request->user()->id,
            auditable: $company,
            oldValues: ['nav_environment' => $oldEnv?->value],
            newValues: ['nav_environment' => $env->value],
        );

        return response()->json(['active_environment' => $env]);
    }

    private function toPublic(CompanyNavCredential $c): array
    {
        return [
            'environment'      => $c->environment,
            'nav_tax_number'   => $c->nav_tax_number,
            'has_login'        => ! empty($c->nav_login),
            'has_password'     => ! empty($c->nav_password),
            'has_signing_key'  => ! empty($c->nav_signing_key),
            'has_exchange_key' => ! empty($c->nav_exchange_key),
            'is_active'        => $c->is_active,
        ];
    }
}
