<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesGroup;

/**
 * Cégek közötti (superadmin) értékesítő csoport nézet — CSAK OLVASÁS.
 *
 * Miért külön kontroller és külön útvonal? A SalesGroupController minden
 * végpontja a BelongsToCompany globális scope-ra és az
 * assertBelongsToCurrentCompany() őrre épül; egy "néha átlép a cégen"
 * kapcsolóval a scope-garancia elveszne. A cross-company olvasás ezért saját,
 * route-model-binding NÉLKÜLI útvonalon él (a binding úgyis az
 * EnsureCompanyContext előtt futna), és kizárólag listát ad — szerkesztés
 * továbbra is cégre scope-olva, a SalesGroupControlleren keresztül történik.
 *
 * @group Értékesítő csoportok
 */
class AdminSalesGroupController extends Controller
{
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
}
