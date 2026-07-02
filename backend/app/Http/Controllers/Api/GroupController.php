<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/** @group Csoportok */
class GroupController extends Controller
{
    use EnforcesCompanyScope;

    public function __construct(private CurrentCompany $currentCompany) {}

    public function index(Request $request)
    {
        $this->authorize('group.view');

        $groups = Group::withCount(['users', 'permissions'])
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json($groups);
    }

    public function store(Request $request)
    {
        $this->authorize('group.manage');

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $group = Group::create($data);

        return response()->json($group, 201);
    }

    public function show(Group $group)
    {
        $this->assertBelongsToCurrentCompany($group);
        $this->authorize('group.view');

        return response()->json(
            $group->load(['permissions', 'users'])
        );
    }

    public function update(Request $request, Group $group)
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

        $group->update($data);

        return response()->json($group);
    }

    public function destroy(Group $group)
    {
        $this->assertBelongsToCurrentCompany($group);
        $this->authorize('group.manage');

        if ($group->is_system) {
            return response()->json(['message' => 'Rendszer-csoport nem törölhető.'], 422);
        }

        $group->delete();

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
