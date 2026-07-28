<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Enums\NavEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @group Cég
 *
 * Operates only on the caller's active company (docs/er-model.md tenant
 * model) — there is no index/store/destroy here; creating new companies
 * and onboarding their first user is a separate, not-yet-built flow.
 */
class CompanyController extends Controller
{
    /** GET /api/companies — az összes cég listája (csak szuperadmin) */
    public function index(Request $request)
    {
        abort_unless($request->user()->is_superadmin, 403);

        $companies = Company::withCount('users')
            ->orderBy('name')
            ->paginate(50);

        return CompanyResource::collection($companies);
    }

    /** POST /api/companies — új cég létrehozása (csak szuperadmin) */
    public function store(StoreCompanyRequest $request, AuditLogger $auditLogger)
    {
        $data = array_merge([
            'country_code'   => 'HU',
            'base_currency'  => 'HUF',
            'nav_environment' => NavEnvironment::Test,
            'is_active'      => true,
        ], array_filter($request->validated(), fn ($v) => $v !== null));

        $company = Company::create($data);

        foreach ([
            [DocumentType::Invoice,       'SZ'],
            [DocumentType::Receipt,       'NY'],
            [DocumentType::InvoiceStorno, 'SZSZT'],
            [DocumentType::ReceiptStorno, 'NYSZT'],
        ] as [$type, $prefix]) {
            DocumentSeries::withoutGlobalScope('company')->create([
                'company_id'    => $company->id,
                'document_type' => $type->value,
                'prefix'        => $prefix,
                'reset_yearly'  => true,
                'next_number'   => 1,
            ]);
        }

        $request->user()->companies()->syncWithoutDetaching([$company->id => ['is_default' => false]]);

        $auditLogger->log('company.manage', $company->id, $request->user()->id, $company, [], $company->toArray());

        return CompanyResource::make(
            $company->loadMissing(['creator:id,name', 'updater:id,name'])
        )->response()->setStatusCode(201);
    }

    public function show(CurrentCompany $currentCompany)
    {
        $this->authorize('company.view');

        // Blame-adat (created_by/updated_by) csak az egy-rekordos válaszokban
        // jelenik meg — a cég-listát ugyanez a Resource szolgálja ki, de ott a
        // reláció nincs betöltve, így a WithBlameable trait whenLoaded() kapuja
        // kihagyja a mezőket (nincs N+1). Az oszlop-korlátozás (:id,name)
        // megakadályozza, hogy felesleges/érzékeny user-mező töltődjön be.
        return CompanyResource::make(
            Company::findOrFail($currentCompany->id())
                ->loadMissing(['creator:id,name', 'updater:id,name'])
        );
    }

    public function update(UpdateCompanyRequest $request, CurrentCompany $currentCompany, AuditLogger $auditLogger)
    {
        $company   = Company::findOrFail($currentCompany->id());
        $validated = $request->validated();

        // Guard: prefix nem null-ra állítható, amíg a cégnek vannak értékesítő csoportjai
        if (array_key_exists('group_prefix', $validated)
            && $validated['group_prefix'] === null
            && $company->salesGroups()->exists()) {
            return response()->json(
                ['message' => 'Előbb töröld a csoportokat, vagy adj meg új prefixet.'],
                422
            );
        }

        $oldValues = $company->only(array_keys($validated));

        try {
            $company->update($validated);
        } catch (\Illuminate\Database\QueryException $e) {
            // 23505 = PostgreSQL unique_violation — akkor fordul elő, ha két egyidejű kérés
            // mindkettő átcsúszik a Rule::unique validáción, de csak az egyik nyeri a DB-versenyt
            if ($e->getCode() === '23505') {
                return response()->json(
                    ['message' => 'Ez a prefix már foglalt, válassz másikat.'],
                    422
                );
            }
            throw $e;
        }

        $newValues = $company->fresh()->only(array_keys($validated));

        if ($oldValues !== $newValues) {
            $auditLogger->log('company.manage', $company->id, $request->user()->id, $company, $oldValues, $newValues);
        }

        return CompanyResource::make(
            $company->loadMissing(['creator:id,name', 'updater:id,name'])
        );
    }

    /** POST /api/company/logo — feltölt egy logót (max 2 MB, jpeg/png/gif/webp) */
    public function uploadLogo(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        $this->authorize('company.manage');

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:2048'],
        ]);

        $company = Company::findOrFail($currentCompany->id());

        // Régi logó törlése
        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $company->update(['logo_path' => $path]);

        return response()->json([
            'logo_url' => Storage::disk('public')->url($path),
        ]);
    }

    /** DELETE /api/company/logo — törli a logót */
    public function deleteLogo(CurrentCompany $currentCompany): JsonResponse
    {
        $this->authorize('company.manage');

        $company = Company::findOrFail($currentCompany->id());

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $company->update(['logo_path' => null]);
        }

        return response()->json(null, 204);
    }
}
