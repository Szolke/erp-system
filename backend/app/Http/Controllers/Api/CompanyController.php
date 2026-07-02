<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
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
    public function show(CurrentCompany $currentCompany)
    {
        $this->authorize('company.view');

        return CompanyResource::make(Company::findOrFail($currentCompany->id()));
    }

    public function update(UpdateCompanyRequest $request, CurrentCompany $currentCompany, AuditLogger $auditLogger)
    {
        $company = Company::findOrFail($currentCompany->id());

        $oldValues = $company->only(array_keys($request->validated()));
        $company->update($request->validated());
        $newValues = $company->fresh()->only(array_keys($request->validated()));

        if ($oldValues !== $newValues) {
            $auditLogger->log('company.manage', $company->id, $request->user()->id, $company, $oldValues, $newValues);
        }

        return CompanyResource::make($company);
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
