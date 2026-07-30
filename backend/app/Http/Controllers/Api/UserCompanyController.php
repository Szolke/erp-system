<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** @group Felhasználók */
class UserCompanyController extends Controller
{
    public function __construct(private AuditLogger $auditLogger) {}

    /** GET /api/users/{user}/companies — a user jelenlegi cégei (superadmin) */
    public function index(Request $request, User $user)
    {
        abort_unless($request->user()->is_superadmin, 403);

        return response()->json([
            'data' => $user->companies()
                ->get(['companies.id', 'companies.name', 'companies.tax_number'])
                ->makeHidden('pivot'),
        ]);
    }

    /** POST /api/users/{user}/companies/{company} — hozzárendelés (superadmin, idempotens) */
    public function attach(Request $request, User $user, Company $company)
    {
        abort_unless($request->user()->is_superadmin, 403);

        if (! $user->companies()->whereKey($company->id)->exists()) {
            $user->companies()->attach($company->id);

            $this->auditLogger->log(
                'user.company_attached',
                $company->id,
                $request->user()->id,
                $user,
                null,
                ['company_id' => $company->id, 'company_name' => $company->name],
            );
        }

        return response()->json([
            'data' => $user->companies()
                ->get(['companies.id', 'companies.name', 'companies.tax_number'])
                ->makeHidden('pivot'),
        ]);
    }

    /** DELETE /api/users/{user}/companies/{company} — leválasztás (superadmin; az utolsó cégből nem lehet kivenni) */
    public function detach(Request $request, User $user, Company $company)
    {
        abort_unless($request->user()->is_superadmin, 403);

        $wasMember = $user->companies()->whereKey($company->id)->exists();

        if ($wasMember && $user->companies()->count() <= 1) {
            return response()->json([
                'message' => 'A felhasználó legalább egy céghez kell tartozzon. Törlés előtt rendelje hozzá egy másik céghez.',
            ], 422);
        }

        $user->companies()->detach($company->id);

        // A cég-tagsággal együtt a leválasztott cég RBAC-csoport-tagságai is
        // megszűnnek. Nélküle a user bennragad a cég csoportjaiban: a jogfeloldás
        // az aktuális cég kontextusán megy, tehát ez nem azonnali jog-szivárgás,
        // viszont egy későbbi visszarendeléskor a régi csoport-tagságok (és velük a
        // jogok) váratlanul „visszaélednek", és addig is elavult tagsági adat marad.
        //
        // Két csapda egyszerre:
        // 1) A BelongsToMany::detach() a PIVOT táblán (user_group) operál egy friss
        //    lekérdezéssel, ami a relációra rakott where-t némán eldobja — argumentum
        //    nélkül hívva MINDEN cégben törölné a tagságokat. Ezért az id-ket a
        //    reláció-query gyűjti ki, és explicit listaként kapja meg a detach()
        //    (ugyanaz a minta, mint a UserController::destroy()-ban, `8e7ac1a`).
        // 2) Ez a végpont superadmin-only és CÉGEK KÖZÖTT dolgozik: a leválasztott cég
        //    nem feltétlenül az aktuális. A Group globális cég-scope-ja viszont az
        //    AKTUÁLIS cégre szűr, ezért withoutGlobalScope('company') nélkül a
        //    lekérdezés a leválasztott cég csoportjait egyáltalán nem látná, és
        //    csendben nem törölnénk semmit (a `8e7ac1a` hiba inverze: alul-detach).
        $groupIds = $user->groups()
            ->withoutGlobalScope('company')
            ->where('groups.company_id', $company->id)
            ->pluck('groups.id')
            ->all();

        // Szándékosan a $wasMember guardon KÍVÜL: az invariáns, amit tartunk, az hogy
        // egy céghez nem tartozó user ne legyen benne a cég csoportjaiban. Így a
        // végpont a fix előtt keletkezett árva sorokat is felszámolja, ha újra hívják.
        $user->groups()->detach($groupIds);

        if ($wasMember) {
            // If the detached company was the user's default, it would otherwise be
            // left pointing at a company the user no longer belongs to — EnsureCompanyContext
            // falls back to default_company_id when there's no session/header yet (e.g. the
            // first request right after login), so a stale value 403s the user out immediately.
            if ($user->default_company_id === $company->id) {
                $user->update([
                    'default_company_id' => $user->companies()->orderBy('companies.id')->value('companies.id'),
                ]);
            }

            $this->auditLogger->log(
                'user.company_detached',
                $company->id,
                $request->user()->id,
                $user,
                ['company_id' => $company->id, 'company_name' => $company->name],
                null,
            );
        }

        // Az elvesztett RBAC-csoport-tagságokat külön naplózzuk: a pivot sorok nyom
        // nélkül tűnnek el, így ez az EGYETLEN forrás, amiből egy téves leválasztás
        // után visszaállítható, mely csoportokban volt a felhasználó. A payload
        // szándékosan azonos alakú a UserController::destroy() naplósorával
        // (company_id + group_ids), hogy egy naplóolvasó mindkét útvonalat kezelje.
        if ($groupIds !== []) {
            $this->auditLogger->log(
                'user.groups_detached',
                $company->id,
                $request->user()->id,
                $user,
                ['company_id' => $company->id, 'group_ids' => $groupIds],
                null,
            );
        }

        return response()->noContent();
    }
}
