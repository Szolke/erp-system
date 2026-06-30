<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;

/**
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
}
