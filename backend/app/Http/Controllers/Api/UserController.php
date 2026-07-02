<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermissionEffect;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/** @group Felhasználók */
class UserController extends Controller
{
    public function __construct(private CurrentCompany $currentCompany) {}

    public function index(Request $request)
    {
        $this->authorize('user.view');

        $users = User::whereHas('companies', fn ($q) => $q->where('companies.id', $this->currentCompany->id()))
            ->with(['groups' => fn ($q) => $q->where('company_id', $this->currentCompany->id())])
            ->when($request->string('search')->trim()->isNotEmpty(), function ($q) use ($request) {
                $s = $request->string('search')->trim()->value();
                $q->where(fn ($q2) => $q2->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%"));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $this->authorize('user.manage');

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['nullable', Password::min(8)],
        ]);

        $companyId = $this->currentCompany->id();

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
            ]);
        }

        $user->companies()->attach($companyId);

        return response()->json($user->load(['groups' => fn ($q) => $q->where('company_id', $companyId)]), 201);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('user.manage');

        $this->ensureSameCompany($user);

        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'password'  => ['nullable', Password::min(8)],
        ]);

        if ($user->is_superadmin) {
            return response()->json(['message' => 'A szuperadmin felhasználó nem módosítható.'], 422);
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json($user);
    }

    public function destroy(User $user, Request $request)
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
        $user->groups()->whereHas('company', fn ($q) => $q->where('companies.id', $companyId))->detach();

        return response()->noContent();
    }

    public function show(User $user)
    {
        $this->authorize('user.view');
        $this->ensureSameCompany($user);

        $companyId = $this->currentCompany->id();

        $user->load([
            'groups' => fn ($q) => $q->where('company_id', $companyId)->with('permissions'),
        ]);

        $overrides = UserPermissionOverride::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->with('permission')
            ->get()
            ->keyBy('permission_id');

        // Csoportokból eredő jogosultság-ID-k unionja
        $fromGroups = $user->groups->flatMap(fn ($g) => $g->permissions->pluck('id'))->unique()->values();

        return response()->json([
            'user'       => $user,
            'overrides'  => $overrides->map(fn ($o) => $o->effect->value)->toArray(),
            'from_groups' => $fromGroups,
        ]);
    }

    // PUT /api/users/{user}/overrides
    // Body: { "overrides": { "<permission_id>": "allow"|"deny"|null } }
    public function syncOverrides(Request $request, User $user)
    {
        $this->authorize('permission.override');
        $this->ensureSameCompany($user);

        $data = $request->validate([
            'overrides'   => ['required', 'array'],
            'overrides.*' => ['nullable', 'string', 'in:allow,deny'],
        ]);

        $companyId = $this->currentCompany->id();
        $permissionIds = Permission::pluck('id')->all();

        foreach ($data['overrides'] as $permId => $effect) {
            if (!in_array((int) $permId, $permissionIds)) continue;

            if ($effect === null) {
                UserPermissionOverride::where('user_id', $user->id)
                    ->where('company_id', $companyId)
                    ->where('permission_id', $permId)
                    ->delete();
            } else {
                UserPermissionOverride::updateOrCreate(
                    ['user_id' => $user->id, 'company_id' => $companyId, 'permission_id' => $permId],
                    ['effect' => PermissionEffect::from($effect)]
                );
            }
        }

        return $this->show($user);
    }

    private function ensureSameCompany(User $user): void
    {
        if (!$user->companies()->whereKey($this->currentCompany->id())->exists()) {
            abort(403, 'A felhasználó nem tagja ennek a cégnek.');
        }
    }
}
