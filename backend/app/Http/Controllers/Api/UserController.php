<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermissionEffect;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** @group Felhasználók */
class UserController extends Controller
{
    public function __construct(private CurrentCompany $currentCompany) {}

    public function index(Request $request)
    {
        $this->authorize('user.view');

        $users = User::whereHas('companies', fn ($q) => $q->where('companies.id', $this->currentCompany->id()))
            ->with(['groups' => fn ($q) => $q->where('company_id', $this->currentCompany->id()), 'jobPosition'])
            ->when($request->string('search')->trim()->isNotEmpty(), function ($q) use ($request) {
                $s = $request->string('search')->trim()->value();
                $q->where(fn ($q2) => $q2->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%"));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json($users);
    }

    public function store(Request $request, AuditLogger $auditLogger)
    {
        $this->authorize('user.manage');

        $companyId = $this->currentCompany->id();

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['nullable', Password::min(8)],
            // Csak globális VAGY az aktuális céghez tartozó, ÉS aktív munkakörre
            // mutathat — egy cég-admin ne tudjon másik cég munkakörét rendelni,
            // és inaktív munkakör új hozzárendelése tiltott (a StoreAssetRequest
            // asset_type_id-mintáját követve).
            'job_position_id' => [
                'nullable', 'integer',
                Rule::exists('job_positions', 'id')->where(
                    fn ($query) => $query
                        ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                        ->where('active', true)
                ),
            ],
        ]);

        // Ha már létezik a felhasználó, csak hozzárendeljük a céghez
        $user = User::where('email', $data['email'])->first();

        if ($user) {
            if ($user->companies()->whereKey($companyId)->exists()) {
                return response()->json(['message' => 'A felhasználó már tagja ennek a cégnek.'], 422);
            }
        } else {
            $user = User::create([
                'name'               => $data['name'],
                'email'              => $data['email'],
                'password'           => Hash::make($data['password'] ?? str()->random(16)),
                'default_company_id' => $companyId,
                'job_position_id'    => $data['job_position_id'] ?? null,
            ]);

            // getHidden() (password, remember_token) takes care of the secret —
            // logChange() masks it into changed_secret_fields, never the hash.
            $auditLogger->logChange(
                'user.create',
                $companyId,
                $request->user()->id,
                $user,
                [],
                $user->only(['name', 'email', 'password', 'default_company_id', 'job_position_id']),
            );
        }

        $user->companies()->attach($companyId);

        return response()->json(
            $user->load(['groups' => fn ($q) => $q->where('company_id', $companyId), 'jobPosition']),
            201
        );
    }

    public function update(Request $request, User $user, AuditLogger $auditLogger)
    {
        $this->authorize('user.manage');

        $this->ensureSameCompany($user);

        $companyId = $this->currentCompany->id();
        $currentJobPositionId = $user->job_position_id;

        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'password'  => ['nullable', Password::min(8)],
            // Ugyanaz a láthatósági kör, mint store-nál. Az 'active' feltétel
            // CSAK akkor érvényesül, ha a beküldött érték ténylegesen ELTÉR a
            // user jelenlegi munkakörétől — egy időközben inaktívvá vált, már
            // hozzárendelt munkakör nem kényszerül eltávolításra, ha a kliens
            // változatlanul küldi vissza ugyanazt az ID-t.
            'job_position_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('job_positions', 'id')->where(function ($query) use ($companyId, $currentJobPositionId, $request) {
                    $query->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));

                    if ((int) $request->input('job_position_id') !== $currentJobPositionId) {
                        $query->where('active', true);
                    }
                }),
            ],
        ]);

        if ($user->is_superadmin) {
            return response()->json(['message' => 'A szuperadmin felhasználó nem módosítható.'], 422);
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $auditFields = ['name', 'is_active', 'job_position_id', 'password'];
        $oldValues = $user->only($auditFields);
        $user->update($data);
        $newValues = $user->fresh()->only($auditFields);

        // getHidden() (password) masks the hash into changed_secret_fields — see logChange().
        $auditLogger->logChange('user.update', $this->currentCompany->id(), $request->user()->id, $user, $oldValues, $newValues);

        return response()->json($user->load('jobPosition'));
    }

    public function destroy(User $user, Request $request, AuditLogger $auditLogger)
    {
        $this->authorize('user.manage');

        $this->ensureSameCompany($user);

        if ($user->is_superadmin) {
            return response()->json(['message' => 'A szuperadmin felhasználó nem törölhető.'], 422);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Saját magát nem távolíthatja el.'], 422);
        }

        $companyId = $this->currentCompany->id();
        $user->companies()->detach($companyId);

        // FONTOS: a BelongsToMany::detach() a PIVOT táblán (user_group) operál egy
        // friss lekérdezéssel, ami CSAK a wherePivot*() feltételeket és a szülő
        // kulcsát veszi figyelembe — a relációra rakott where/whereHas és a Group
        // globális cég-scope-ja némán elvész. Argumentum nélkül hívva ezért MINDEN
        // cégben törölte volna a tagságokat (cross-company adatromlás). A helyes
        // minta: az id-ket a reláció-query-vel gyűjtjük ki (ez tiszteletben tartja
        // a szűrést), és explicit listaként adjuk át a detach()-nek.
        $groupIds = $user->groups()->where('groups.company_id', $companyId)->pluck('groups.id')->all();
        $user->groups()->detach($groupIds);

        // Ez nem a User sor törlése (a felhasználó más cégben megmarad) — a
        // ténylegesen történt változás a cég-tagság megszűnése, ezt auditáljuk.
        $auditLogger->logChange(
            'user.company_removed',
            $companyId,
            $request->user()->id,
            $user,
            ['company_id' => $companyId],
            [],
        );

        // Az elvesztett RBAC-csoport-tagságokat külön naplózzuk: a pivot sorok
        // nyom nélkül tűnnek el, így ez az EGYETLEN forrás, amiből egy téves
        // kivétel után visszaállítható, mely csoportokban volt a felhasználó.
        if ($groupIds !== []) {
            $auditLogger->logChange(
                'user.groups_detached',
                $companyId,
                $request->user()->id,
                $user,
                ['company_id' => $companyId, 'group_ids' => $groupIds],
                [],
            );
        }

        return response()->noContent();
    }

    public function show(Request $request, User $user)
    {
        // Saját profil: user.view jog nélkül is megtekinthető (token-eszközkezelőhöz szükséges).
        // Más user profilja: user.view jog kell.
        if ($request->user()->id !== $user->id) {
            $this->authorize('user.view');
        }
        $this->ensureSameCompany($user);

        $companyId = $this->currentCompany->id();

        // A creator/updater oszlop-korlátozott eager-loadja a blame-adathoz kell
        // (l. lent) — a beágyazott groups/jobPosition kollekciók SAJÁT blame-je
        // szándékosan nem töltődik: az a detail-nézetnek nem adata, és listányi
        // N+1-et hozna.
        $user->load([
            'groups' => fn ($q) => $q->where('company_id', $companyId)->with('permissions'),
            'jobPosition',
        ])->loadMissing(['creator:id,name', 'updater:id,name']);

        $overrides = UserPermissionOverride::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->with('permission')
            ->get()
            ->keyBy('permission_id');

        // Csoportokból eredő jogosultság-ID-k unionja
        $fromGroups = $user->groups->flatMap(fn ($g) => $g->permissions->pluck('id'))->unique()->values();

        return response()->json([
            // Nyers modell-JSON (nincs UserResource), ezért a blame-adatot
            // kézzel fésüljük bele — ugyanaz az alak, mint a Resource-alapú
            // detail-végpontokon. A betöltött creator/updater relációt
            // kihagyjuk (ne duplikálódjon), a created_by/updated_by FK-t pedig
            // a blame-objektum írja felül. A lista (index) érintetlen marad.
            'user'       => [
                ...Arr::except($user->toArray(), ['creator', 'updater']),
                ...$user->blameData(),
            ],
            'overrides'  => $overrides->map(fn ($o) => $o->effect->value)->toArray(),
            'from_groups' => $fromGroups,
        ]);
    }

    // PUT /api/users/{user}/overrides
    // Body: { "overrides": { "<permission_id>": "allow"|"deny"|null } }
    public function syncOverrides(Request $request, User $user, AuditLogger $auditLogger)
    {
        $this->authorize('permission.override');
        $this->ensureSameCompany($user);

        $data = $request->validate([
            'overrides'   => ['required', 'array'],
            'overrides.*' => ['nullable', 'string', 'in:allow,deny'],
        ]);

        $companyId = $this->currentCompany->id();
        $permissionIds = Permission::pluck('id')->all();
        $actorId = $request->user()->id;

        foreach ($data['overrides'] as $permId => $effect) {
            if (!in_array((int) $permId, $permissionIds)) continue;

            $existing = UserPermissionOverride::where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->where('permission_id', $permId)
                ->first();

            $oldEffect = $existing?->effect?->value;

            if ($oldEffect === $effect) {
                continue; // nincs tényleges változás
            }

            if ($effect === null) {
                $oldValues = $existing->only($existing->getFillable());
                $existing->delete();

                $auditLogger->logChange('permission_override.delete', $companyId, $actorId, $existing, $oldValues, []);
            } else {
                $isNew = $existing === null;
                $oldValues = $existing?->only($existing->getFillable()) ?? [];

                $override = UserPermissionOverride::updateOrCreate(
                    ['user_id' => $user->id, 'company_id' => $companyId, 'permission_id' => $permId],
                    ['effect' => PermissionEffect::from($effect)]
                );

                $newValues = $override->fresh()->only($override->getFillable());

                $auditLogger->logChange(
                    $isNew ? 'permission_override.create' : 'permission_override.update',
                    $companyId,
                    $actorId,
                    $override,
                    $oldValues,
                    $newValues,
                );
            }
        }

        return $this->show($request, $user);
    }

    private function ensureSameCompany(User $user): void
    {
        if (!$user->companies()->whereKey($this->currentCompany->id())->exists()) {
            abort(403, 'A felhasználó nem tagja ennek a cégnek.');
        }
    }
}
