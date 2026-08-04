<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use App\Support\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** @group Csoportok */
class GroupController extends Controller
{
    use EnforcesCompanyScope;

    /** Rendezhető oszlopok (l. App\Support\ListSort). `members`/`permissions`
     *  a withCount() által generált `users_count`/`permissions_count`
     *  SELECT-aliasra rendez — PostgreSQL az ORDER BY-ban látja a SELECT
     *  aliasokat, nem kell külön join. */
    private const SORTABLE_COLUMNS = [
        'name'        => 'name',
        'description' => 'description',
        'members'     => 'users_count',
        'permissions' => 'permissions_count',
    ];

    private const DEFAULT_SORT_KEY = 'name';

    private const SORT_TIE_BREAKERS = ['id ASC'];

    public function __construct(private CurrentCompany $currentCompany) {}

    public function index(Request $request)
    {
        $this->authorize('group.view');

        $groups = Group::withCount(['users', 'permissions'])
            ->orderByRaw($this->orderBySql($request))
            ->paginate($this->perPage($request));

        return response()->json($groups);
    }

    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY, 'asc')
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
    }

    public function store(Request $request, AuditLogger $auditLogger)
    {
        $this->authorize('group.manage');

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $group = Group::create($data);

        $auditLogger->logChange(
            'group.create',
            $group->company_id,
            $request->user()->id,
            $group,
            [],
            $group->only($group->getFillable()),
        );

        return response()->json($group, 201);
    }

    public function show(Group $group)
    {
        $this->assertBelongsToCurrentCompany($group);
        $this->authorize('group.view');

        // Ez a végpont nyers modell-JSON-t ad (nincs GroupResource), ezért a
        // blame-adatot kézzel fésüljük bele; a HasBlameable::blameData()
        // ugyanazt az alakot adja, mint a Resource-alapú detail-végpontokon.
        // Két igazítás kell hozzá:
        //  - a betöltött creator/updater relációt kihagyjuk, hogy ne
        //    duplikálódjon a válaszban;
        //  - a created_by/updated_by FK-t (nyers JSON-ben egész szám) a
        //    blame-objektum írja felül, hogy a detail-szerződés minden
        //    végponton azonos legyen. A lista (index) érintetlen marad.
        $group->load(['permissions', 'users'])
            ->loadMissing(['creator:id,name', 'updater:id,name']);

        return response()->json([
            ...Arr::except($group->toArray(), ['creator', 'updater']),
            ...$group->blameData(),
        ]);
    }

    public function update(Request $request, Group $group, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($group);
        $this->authorize('group.manage');

        if ($group->is_system) {
            return response()->json(['message' => 'Rendszer-csoport nem módosítható.'], 422);
        }

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $oldValues = $group->only(array_keys($data));
        $group->update($data);
        $newValues = $group->fresh()->only(array_keys($data));

        $auditLogger->logChange('group.update', $group->company_id, $request->user()->id, $group, $oldValues, $newValues);

        return response()->json($group);
    }

    public function destroy(Group $group, Request $request, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($group);
        $this->authorize('group.manage');

        if ($group->is_system) {
            return response()->json(['message' => 'Rendszer-csoport nem törölhető.'], 422);
        }

        $oldValues = $group->only($group->getFillable());
        $companyId = $group->company_id;
        $group->delete();

        $auditLogger->logChange('group.delete', $companyId, $request->user()->id, $group, $oldValues, []);

        return response()->noContent();
    }

    // PUT /api/groups/{group}/permissions  — jogosultságok szinkronizálása
    public function syncPermissions(Request $request, Group $group)
    {
        $this->assertBelongsToCurrentCompany($group);
        $this->authorize('group.manage');

        $data = $request->validate([
            'permission_ids'   => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $group->permissions()->sync($data['permission_ids']);

        return response()->json($group->load('permissions'));
    }

    // POST /api/groups/{group}/members
    public function addMember(Request $request, Group $group)
    {
        $this->assertBelongsToCurrentCompany($group);
        $this->authorize('group.manage');

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::findOrFail($data['user_id']);

        // Felhasználó tagja-e ennek a cégnek?
        if (!$user->companies()->whereKey($this->currentCompany->id())->exists()) {
            return response()->json(['message' => 'A felhasználó nem tagja ennek a cégnek.'], 422);
        }

        $group->users()->syncWithoutDetaching([$data['user_id']]);

        return response()->json(['message' => 'Hozzáadva.'], 201);
    }

    // DELETE /api/groups/{group}/members/{user}
    public function removeMember(Group $group, User $user)
    {
        $this->assertBelongsToCurrentCompany($group);
        $this->authorize('group.manage');

        $group->users()->detach($user->id);

        return response()->noContent();
    }
}
