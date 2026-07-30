<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Concerns\ManagesSalesGroups;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesGroupRequest;
use App\Http\Requests\SyncSalesGroupUsersRequest;
use App\Http\Requests\UpdateSalesGroupRequest;
use App\Http\Resources\SalesGroupResource;
use App\Models\SalesGroup;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** @group Értékesítő csoportok */
class SalesGroupController extends Controller
{
    use EnforcesCompanyScope, ManagesSalesGroups;

    public function index(Request $request)
    {
        $this->authorize('sales_group.view');

        $groups = SalesGroup::with('company')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return SalesGroupResource::collection($groups);
    }

    public function store(StoreSalesGroupRequest $request, AuditLogger $auditLogger)
    {
        // A jog-ellenőrzést a StoreSalesGroupRequest::authorize() végzi
        // (sales_group.create); a művelet törzse a ManagesSalesGroups concernben
        // közös a cross-company úttal.
        return $this->createSalesGroup($request->validated(), $request->user()->id, $auditLogger);
    }

    public function show(SalesGroup $salesGroup)
    {
        $this->assertBelongsToCurrentCompany($salesGroup);
        $this->authorize('sales_group.view');

        // Blame-adat (created_by/updated_by) csak az egy-rekordos válaszokban
        // jelenik meg — a listát ugyanez a Resource szolgálja ki, de ott a
        // reláció nincs betöltve, így a WithBlameable trait whenLoaded() kapuja
        // kihagyja a mezőket (nincs N+1). Az oszlop-korlátozás (:id,name)
        // megakadályozza, hogy felesleges/érzékeny user-mező töltődjön be.
        $salesGroup->load('company')->loadMissing(['creator:id,name', 'updater:id,name']);

        return SalesGroupResource::make($salesGroup);
    }

    public function update(UpdateSalesGroupRequest $request, SalesGroup $salesGroup, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($salesGroup);

        return $this->updateSalesGroup($salesGroup, $request->validated(), $request->user()->id, $auditLogger);
    }

    public function destroy(SalesGroup $salesGroup, Request $request, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($salesGroup);
        $this->authorize('sales_group.delete');

        return $this->deleteSalesGroup($salesGroup, $request->user()->id, $auditLogger);
    }

    /**
     * GET /api/sales-groups/{salesGroup}/users — a csoport tagjai.
     *
     * Az oszlop-korlátozás (:id,name,email) szándékos: a tagválasztóhoz ennyi
     * kell, és így nem szivárog ki felesleges user-mező.
     */
    public function users(SalesGroup $salesGroup)
    {
        $this->assertBelongsToCurrentCompany($salesGroup);
        $this->authorize('sales_group.view');

        return response()->json([
            'data' => $salesGroup->users()
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email']),
        ]);
    }

    /**
     * PUT /api/sales-groups/{salesGroup}/users — tagság-szinkron.
     *
     * A same-company garanciát a SyncSalesGroupUsersRequest adja (minden
     * user_id igazoltan az aktuális cég felhasználója), ezért itt már nyugodtan
     * sync()-elhetünk. A pivotnak nincs blame-oszlopa (l. a migráció
     * kommentjét), a "ki mikor mit kapcsolt" az audit-naplóból derül ki.
     */
    public function syncUsers(SyncSalesGroupUsersRequest $request, SalesGroup $salesGroup, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($salesGroup);

        $oldIds = $salesGroup->users()->pluck('users.id')->sort()->values()->all();

        $salesGroup->users()->sync($request->validated()['user_ids']);

        $newIds = $salesGroup->users()->pluck('users.id')->sort()->values()->all();

        // logChange() diffel: ha a tagság ténylegesen nem változott (pl. ugyanaz
        // a lista jött be újra), nem keletkezik audit-sor.
        $auditLogger->logChange(
            'sales_group.members_sync',
            $salesGroup->company_id,
            $request->user()->id,
            $salesGroup,
            ['user_ids' => $oldIds],
            ['user_ids' => $newIds],
        );

        return response()->json([
            'data' => $salesGroup->users()
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email']),
        ]);
    }
}
