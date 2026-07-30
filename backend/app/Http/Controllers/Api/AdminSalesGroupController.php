<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ManagesSalesGroups;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesGroupRequest;
use App\Http\Requests\UpdateSalesGroupRequest;
use App\Models\SalesGroup;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/**
 * Cégek közötti (superadmin) értékesítő csoport kezelés — olvasás + CRUD.
 *
 * Miért külön kontroller és külön útvonal? A SalesGroupController minden
 * végpontja a BelongsToCompany globális scope-ra és az
 * assertBelongsToCurrentCompany() őrre épül; egy "néha átlép a cégen"
 * kapcsolóval a scope-garancia elveszne — ugyanabban az osztályban egyes
 * metódusokon valódi őr lenne, másokon tautológia. A cross-company út ezért
 * saját útvonalakon él.
 *
 * ═══ HOGYAN MŰKÖDIK AZ ÍRÁS ═══
 * Az index() route-model-binding nélkül, withoutGlobalScope-pal olvas. Az írási
 * végpontok viszont NEM építenek párhuzamos, scope-mentes CRUD-ot: rajtuk a
 * `company.cross` middleware (ResolveCrossCompanyContext) a kérés idejére a CÉL
 * cégre állítja a CurrentCompany-t, MÉG a FormRequest feloldása előtt — onnantól
 * a megszokott gépezet fut változatlanul (validáció, globális scope, auto-stamp,
 * audit). A közös művelet-törzs a ManagesSalesGroups concernben van, tehát a
 * cégen belüli és a cross-company út bitre ugyanazt a logikát futtatja.
 *
 * ═══ JOGOSULTSÁG ═══
 * Nincs új permission-kulcs: hard superadmin-kapu. Az elsődleges őr a
 * middleware (a kontextus felülírása ELŐTT fut); az itteni abort_unless() a
 * második, független réteg arra az esetre, ha valaki a middleware nélkül kötné
 * be az útvonalat. A FormRequestek can('sales_group.create'/'edit') hívása a
 * MÁR átállított kontextuson fut, tehát a modul-kaput a CÉL cégre is érvényesíti
 * (kikapcsolt sales_group modulú cégbe superadmin sem ír) — a route-on ülő
 * module:sales_group middleware ezzel szemben a HÍVÓ cégére vonatkozik.
 *
 * @group Értékesítő csoportok
 */
class AdminSalesGroupController extends Controller
{
    use ManagesSalesGroups;

    /**
     * GET /api/admin/sales-groups
     *
     * A jogosultság-kapu a sales_group.view_cross_company kulcs: superadminnál a
     * Gate::before enged át (AppServiceProvider), normál felhasználónál a
     * PermissionChecker sosem adja meg (SUPERADMIN_ONLY_KEYS) → 403. A modul-kapu
     * mindkét úton érvényes: kikapcsolt sales_group modulnál a Gate::before is
     * false-t ad, a route-on ülő module:sales_group middleware pedig 404-et.
     */
    public function index()
    {
        $this->authorize('sales_group.view_cross_company');

        $groups = SalesGroup::withoutGlobalScope('company')
            // Eager load, hogy a cégenkénti csoportosítás és a taglista ne
            // termeljen N+1-et. Az oszlop-korlátozás szándékos: a nézethez
            // ennyi kell, felesleges/érzékeny user-mező nem megy ki.
            ->with([
                'company:id,name,group_prefix',
                'users:id,name,email',
            ])
            ->orderBy('company_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $groups->map(fn (SalesGroup $group) => [
                'id'           => $group->id,
                'name'         => $group->name,
                // Ugyanaz a képlet, mint a SalesGroupResource-ban: a csoport
                // SAJÁT cégének prefixe (nem az aktuális cégé) — cross-company
                // listában ez különösen fontos.
                'display_name' => $group->company?->group_prefix
                    ? "{$group->company->group_prefix}_{$group->name}"
                    : $group->name,
                'company'      => [
                    'id'   => $group->company?->id,
                    'name' => $group->company?->name,
                ],
                'users'        => $group->users->map(fn ($user) => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * POST /api/admin/sales-groups — csoport létrehozása TETSZŐLEGES cégben.
     *
     * A cél céget a kérés `company_id` mezője adja; azt a company.cross
     * middleware validálja (létező cég) és állítja be kontextusként — ezért a
     * StoreSalesGroupRequest név-egyediség szabálya már a cél cégre fut, és a
     * `company_id` a BelongsToCompany auto-stampjéből kerül a rekordra (a
     * validated() csak a nevet tartalmazza, tehát a kérés törzse nem tud más
     * céget becsempészni).
     */
    public function store(StoreSalesGroupRequest $request, AuditLogger $auditLogger)
    {
        abort_unless($request->user()->is_superadmin, 403);

        return $this->createSalesGroup(
            $request->validated(),
            $request->user()->id,
            $auditLogger,
            $this->crossCompanyAuditContext(),
        );
    }

    /**
     * PUT /api/admin/sales-groups/{sales_group} — átnevezés bármelyik cégben.
     *
     * Az útvonal-paraméter neve szándékosan `sales_group`: az
     * UpdateSalesGroupRequest ezen a néven olvassa ki a route-modellt a
     * név-egyediség self-exclude-jához. Más néven a szabály önmagával ütköztetné
     * a csoportot (a saját nevére mentés hamis 422-t adna).
     */
    public function update(UpdateSalesGroupRequest $request, SalesGroup $salesGroup, AuditLogger $auditLogger)
    {
        abort_unless($request->user()->is_superadmin, 403);

        return $this->updateSalesGroup(
            $salesGroup,
            $request->validated(),
            $request->user()->id,
            $auditLogger,
            $this->crossCompanyAuditContext(),
        );
    }

    /**
     * DELETE /api/admin/sales-groups/{sales_group} — törlés bármelyik cégben.
     *
     * A törlés szemantikája megegyezik a cégen belülivel (nincs tagság-alapú
     * tiltás, a pivot-takarítást a DB cascade végzi) — l. ManagesSalesGroups.
     * Az authorize() a már átállított kontextuson fut, tehát a cél cég
     * modul-kapuját is érvényesíti.
     */
    public function destroy(SalesGroup $salesGroup, Request $request, AuditLogger $auditLogger)
    {
        abort_unless($request->user()->is_superadmin, 403);
        $this->authorize('sales_group.delete');

        return $this->deleteSalesGroup(
            $salesGroup,
            $request->user()->id,
            $auditLogger,
            $this->crossCompanyAuditContext(),
        );
    }

    /**
     * Cross-company jelölők az audit-payloadba, migráció nélkül (JSON).
     *
     * A `target_company_id` külön kulcs, nem a fillable `company_id`: az utóbbi
     * a törlés new_values-ából hiányozna, és így a riportok egyetlen, mindig
     * jelenlévő mezőből tudják kiolvasni, MELYIK cégben történt a művelet.
     *
     * @return array<string, mixed>
     */
    private function crossCompanyAuditContext(): array
    {
        return [
            'cross_company'     => true,
            'target_company_id' => app(CurrentCompany::class)->id(),
        ];
    }
}
