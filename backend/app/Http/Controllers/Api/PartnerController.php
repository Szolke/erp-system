<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartnerRequest;
use App\Http\Requests\UpdatePartnerRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Partner;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** @group Partnerek */
class PartnerController extends Controller
{
    use EnforcesCompanyScope;

    public function index(Request $request)
    {
        $this->authorize('partner.view');

        $partners = Partner::query()
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();
                $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('tax_number', 'ilike', "%{$search}%"));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return PartnerResource::collection($partners);
    }

    public function store(StorePartnerRequest $request, AuditLogger $auditLogger)
    {
        $partner = Partner::create($request->validated())->refresh();

        $auditLogger->logChange(
            'partner.create',
            $partner->company_id,
            $request->user()->id,
            $partner,
            [],
            $partner->only($partner->getFillable()),
        );

        return PartnerResource::make($partner)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Partner $partner)
    {
        $this->assertBelongsToCurrentCompany($partner);
        $this->authorize('partner.view');

        return PartnerResource::make($partner);
    }

    public function update(UpdatePartnerRequest $request, Partner $partner, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($partner);

        $oldValues = $partner->only($partner->getFillable());
        $partner->update($request->validated());
        $newValues = $partner->fresh()->only($partner->getFillable());

        $auditLogger->logChange('partner.update', $partner->company_id, $request->user()->id, $partner, $oldValues, $newValues);

        return PartnerResource::make($partner);
    }

    public function destroy(Partner $partner, Request $request, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($partner);
        $this->authorize('partner.delete');

        if ($partner->invoices()->exists() || $partner->receipts()->exists()) {
            abort(409, 'A partner nem törölhető, mert tartoznak hozzá bizonylatok.');
        }

        $oldValues = $partner->only($partner->getFillable());
        $companyId = $partner->company_id;
        $partner->delete();

        $auditLogger->logChange('partner.delete', $companyId, $request->user()->id, $partner, $oldValues, []);

        return response()->noContent();
    }
}
