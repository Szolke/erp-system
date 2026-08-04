<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartnerRequest;
use App\Http\Requests\UpdatePartnerRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Partner;
use App\Services\AuditLogger;
use App\Support\ListSort;
use Illuminate\Http\Request;

/** @group Partnerek */
class PartnerController extends Controller
{
    use EnforcesCompanyScope;

    /** Rendezhető oszlopok (l. App\Support\ListSort). A `city` a `billing_city`
     *  oszlopra rendez — a partnerlistán a szállítási cím nem jelenik meg. */
    private const SORTABLE_COLUMNS = [
        'name'       => 'name',
        'tax_number' => 'tax_number',
        'type'       => 'type',
        'city'       => 'billing_city',
        'email'      => 'email',
    ];

    private const DEFAULT_SORT_KEY = 'name';

    private const SORT_TIE_BREAKERS = ['id ASC'];

    public function index(Request $request)
    {
        $this->authorize('partner.view');

        $partners = Partner::query()
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();
                $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('tax_number', 'ilike', "%{$search}%"));
            })
            ->orderByRaw($this->orderBySql($request))
            ->paginate($this->perPage($request));

        return PartnerResource::collection($partners);
    }

    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY, 'asc')
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
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

        return PartnerResource::make(
            $partner->loadMissing(['creator:id,name', 'updater:id,name'])
        )
            ->response()
            ->setStatusCode(201);
    }

    public function show(Partner $partner)
    {
        $this->assertBelongsToCurrentCompany($partner);
        $this->authorize('partner.view');

        // Blame-adat (created_by/updated_by) csak az egy-rekordos válaszokban
        // jelenik meg — a listát ugyanez a Resource szolgálja ki, de ott a
        // reláció nincs betöltve, így a WithBlameable trait whenLoaded() kapuja
        // kihagyja a mezőket (nincs N+1). Az oszlop-korlátozás (:id,name)
        // megakadályozza, hogy felesleges/érzékeny user-mező töltődjön be.
        return PartnerResource::make(
            $partner->loadMissing(['creator:id,name', 'updater:id,name'])
        );
    }

    public function update(UpdatePartnerRequest $request, Partner $partner, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($partner);

        $oldValues = $partner->only($partner->getFillable());
        $partner->update($request->validated());
        $newValues = $partner->fresh()->only($partner->getFillable());

        $auditLogger->logChange('partner.update', $partner->company_id, $request->user()->id, $partner, $oldValues, $newValues);

        return PartnerResource::make(
            $partner->loadMissing(['creator:id,name', 'updater:id,name'])
        );
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
