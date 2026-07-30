<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\SalesGroupResource;
use App\Models\Company;
use App\Models\SalesGroup;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Az értékesítő csoport írási műveleteinek KÖZÖS törzse.
 *
 * Két kontroller használja, és szándékosan ugyanaz a kód fut mindkettőn:
 *  - SalesGroupController — cégen belüli CRUD (a kontextus a felhasználó
 *    aktív cége),
 *  - AdminSalesGroupController — superadmin cross-company CRUD (a kontextust a
 *    ResolveCrossCompanyContext middleware állította a CÉL cégre, még a
 *    FormRequest feloldása előtt).
 *
 * Mivel a metódusok kizárólag a CurrentCompany-ból dolgoznak (prefix-guard,
 * BelongsToCompany auto-stamp, globális scope), a cross-company út nem igényel
 * külön szabályt: „hol" a művelet, azt a hívás előtt beállított kontextus dönti
 * el. Ezért NINCS itt jogosultság-ellenőrzés sem — azt a hívó kontroller végzi
 * (cégen belül permission-kulcs, cross-company hard superadmin-kapu).
 *
 * Az $auditContext a cross-company jelölőket hordozza (l. az egyes metódusok
 * kommentjét); cégen belüli híváskor üres, így az audit-sorok alakja
 * változatlan marad.
 */
trait ManagesSalesGroups
{
    /**
     * @param  array<string, mixed>  $attributes  a FormRequest validated() kimenete
     * @param  array<string, mixed>  $auditContext
     */
    protected function createSalesGroup(
        array $attributes,
        int $userId,
        AuditLogger $auditLogger,
        array $auditContext = [],
    ): JsonResponse {
        $company = Company::findOrFail(app(CurrentCompany::class)->id());

        if (! $company->group_prefix) {
            return response()->json(
                ['message' => 'Előbb állíts be prefixet a cégbeállításoknál.'],
                422
            );
        }

        // A company_id NEM az $attributes-ből jön: a validated() csak a nevet
        // tartalmazza, a cég-hovatartozást a BelongsToCompany creating-hookja
        // stempeli a CurrentCompany-ból. Cross-company úton ez már a cél cég.
        $salesGroup = SalesGroup::create($attributes);

        $auditLogger->logChange(
            'sales_group.create',
            $salesGroup->company_id,
            $userId,
            $salesGroup,
            [],
            // A jelölők ahhoz a payload-félhez csatlakoznak, amelyik az adatot
            // hordozza — create-nél ez a new_values.
            [...$salesGroup->only($salesGroup->getFillable()), ...$auditContext],
        );

        $salesGroup->load('company')->loadMissing(['creator:id,name', 'updater:id,name']);

        return SalesGroupResource::make($salesGroup)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @param  array<string, mixed>  $attributes  a FormRequest validated() kimenete
     * @param  array<string, mixed>  $auditContext
     */
    protected function updateSalesGroup(
        SalesGroup $salesGroup,
        array $attributes,
        int $userId,
        AuditLogger $auditLogger,
        array $auditContext = [],
    ): JsonResponse {
        // A jelölők MINDKÉT oldalra kerülnek: így kiejtik egymást a logChange()
        // diffjében, tehát egy tényleges változás nélküli mentés (ugyanaz a név)
        // cross-company úton sem termel felesleges audit-sort.
        $oldValues = [...$salesGroup->only($salesGroup->getFillable()), ...$auditContext];
        $salesGroup->update($attributes);
        $newValues = [...$salesGroup->fresh()->only($salesGroup->getFillable()), ...$auditContext];

        $auditLogger->logChange(
            'sales_group.update',
            $salesGroup->company_id,
            $userId,
            $salesGroup,
            $oldValues,
            $newValues,
        );

        $salesGroup->load('company')->loadMissing(['creator:id,name', 'updater:id,name']);

        return SalesGroupResource::make($salesGroup)->response();
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    protected function deleteSalesGroup(
        SalesGroup $salesGroup,
        int $userId,
        AuditLogger $auditLogger,
        array $auditContext = [],
    ): Response {
        // A jelölők a delete adathordozó oldalára, az old_values-ba kerülnek.
        $oldValues = [...$salesGroup->only($salesGroup->getFillable()), ...$auditContext];
        $companyId = $salesGroup->company_id;

        // A sales_group_user pivot sorait a DB-szintű cascadeOnDelete takarítja
        // (l. a pivot migrációját), NEM egy detach() hívás. Ez itt szándékos:
        // a `61d24c8` detach-hibacsalád oka épp az volt, hogy a
        // BelongsToMany::detach() a PIVOT táblán operál és a relációra rakott
        // where-t némán eldobja. Csoport-törlésnél nincs mit szűrni (a csoport
        // ÖSSZES tagsága megszűnik), és a cascade cég-kontextustól függetlenül
        // fut le — tehát cross-company úton sem maradhat árva sor.
        $salesGroup->delete();

        $auditLogger->logChange(
            'sales_group.delete',
            $companyId,
            $userId,
            $salesGroup,
            $oldValues,
            [],
        );

        return response()->noContent();
    }
}
