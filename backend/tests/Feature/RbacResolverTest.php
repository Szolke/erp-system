<?php

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\Company;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\PermissionChecker;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * RBAC resolver tests for PermissionChecker and Gate::before.
 *
 * Resolution rules (docs/er-model.md, principle 3):
 *   1. user_permission_overrides (allow|deny) always wins over group grants.
 *   2. If no override: permission is granted iff any company-scoped group
 *      the user belongs to carries it.
 *   3. is_superadmin=true → Gate::before shortcuts to true before any lookup.
 *
 * Tests are self-contained: catalog data (permissions) is created explicitly
 * in each test; no seeders run automatically.
 */
class RbacResolverTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User $user;
    private Permission $permission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany();

        $this->user = User::create([
            'name'     => 'Test User',
            'email'    => 'rbac-user@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->company->users()->attach($this->user->id);

        $this->permission = Permission::create([
            'key'    => 'invoice.view',
            'module' => 'invoice',
        ]);

        // Set company context so Gate::before can read CurrentCompany::id()
        app(CurrentCompany::class)->set($this->company->id);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ─── Test 1: group membership grants the permission ──────────────────────

    public function test_group_permission_grants_access(): void
    {
        // BelongsToCompany creating-event auto-fills company_id from CurrentCompany
        $group = Group::create(['name' => 'Accountants']);
        $group->users()->attach($this->user->id);
        $group->permissions()->attach($this->permission->id);

        $checker = app(PermissionChecker::class);

        $this->assertTrue(
            $checker->check($this->user, 'invoice.view', $this->company->id),
            'PermissionChecker must return true when the user belongs to a group that has the permission'
        );
        $this->assertTrue(
            Gate::forUser($this->user)->allows('invoice.view'),
            'Gate must allow the permission via the group-grant path'
        );
    }

    // ─── Test 2: explicit DENY override beats group-level ALLOW ──────────────

    public function test_user_level_deny_overrides_group_allow(): void
    {
        $group = Group::create(['name' => 'Accountants']);
        $group->users()->attach($this->user->id);
        $group->permissions()->attach($this->permission->id);

        // Same permission — but with an explicit DENY for this user
        UserPermissionOverride::create([
            'user_id'       => $this->user->id,
            'company_id'    => $this->company->id,
            'permission_id' => $this->permission->id,
            'effect'        => PermissionEffect::Deny,
        ]);

        $checker = app(PermissionChecker::class);

        $this->assertFalse(
            $checker->check($this->user, 'invoice.view', $this->company->id),
            'Explicit DENY must remove the permission even when the user\'s group grants it'
        );
        $this->assertFalse(
            Gate::forUser($this->user)->allows('invoice.view'),
            'Gate must deny when a DENY override is present, regardless of group membership'
        );
    }

    // ─── Test 3: explicit ALLOW without any group membership ─────────────────

    public function test_user_level_allow_without_group_membership(): void
    {
        // No group at all — only an explicit ALLOW override
        UserPermissionOverride::create([
            'user_id'       => $this->user->id,
            'company_id'    => $this->company->id,
            'permission_id' => $this->permission->id,
            'effect'        => PermissionEffect::Allow,
        ]);

        $checker = app(PermissionChecker::class);

        $this->assertTrue(
            $checker->check($this->user, 'invoice.view', $this->company->id),
            'Explicit ALLOW must grant access even without any group membership'
        );
        $this->assertTrue(
            Gate::forUser($this->user)->allows('invoice.view'),
            'Gate must allow when only an explicit ALLOW override is present'
        );
    }

    // ─── Test 4: superadmin is short-circuited in Gate::before ───────────────

    public function test_superadmin_gets_all_permissions_via_gate_before(): void
    {
        $superadmin = User::create([
            'name'          => 'Super Admin',
            'email'         => 'superadmin@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => true,
        ]);
        // No company membership, no groups, no permission grants, no overrides.
        // Gate::before returns true before PermissionChecker is ever consulted.

        $this->assertTrue(
            Gate::forUser($superadmin)->allows('invoice.view'),
            'Superadmin must pass checks for regular permission keys'
        );
        $this->assertTrue(
            Gate::forUser($superadmin)->allows('company.manage'),
            'Superadmin must pass checks for any dotted permission key'
        );
        $this->assertTrue(
            Gate::forUser($superadmin)->allows('nonexistent.invented'),
            'Gate::before must short-circuit before any DB lookup for superadmin'
        );
    }

    // ─── Test 5: superadmin gets full list from PermissionChecker (no groups) ──

    public function test_superadmin_gets_full_permission_list_without_group_membership(): void
    {
        $superadmin = User::create([
            'name'          => 'Super Admin',
            'email'         => 'superadmin@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => true,
        ]);
        // No company membership, no groups, no overrides in $this->company.

        $checker = app(PermissionChecker::class);
        $keys = $checker->effectivePermissionKeys($superadmin, $this->company->id);

        $this->assertContains(
            'invoice.view',
            $keys,
            'Superadmin must receive all permission keys even without any group membership'
        );
        $this->assertCount(
            Permission::count(),
            $keys,
            'Superadmin permission list must equal the full permission catalog'
        );
    }

    // ─── Test 6: normal user permissions are unaffected by the superadmin fix ─

    public function test_normal_user_permissions_unchanged_after_superadmin_fix(): void
    {
        $extraPermission = Permission::create([
            'key'    => 'partner.view',
            'module' => 'partner',
        ]);

        // Group grants only invoice.view — NOT partner.view
        $group = Group::create(['name' => 'Limited Group']);
        $group->users()->attach($this->user->id);
        $group->permissions()->attach($this->permission->id); // invoice.view only

        $checker = app(PermissionChecker::class);
        $keys = $checker->effectivePermissionKeys($this->user, $this->company->id);

        $this->assertContains('invoice.view', $keys, 'Group-granted permission must be present');
        $this->assertNotContains(
            'partner.view',
            $keys,
            'Normal user must not receive permissions outside their group (regression guard)'
        );
        $this->assertCount(1, $keys, 'Normal user must receive exactly the permissions their groups grant');
    }

    // ─── Test 7: superadmin full list is consistent across companies ──────────

    public function test_superadmin_permission_list_consistent_across_companies(): void
    {
        $superadmin = User::create([
            'name'          => 'Super Admin 2',
            'email'         => 'superadmin2@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => true,
        ]);

        $otherCompany = $this->makeCompany();

        $checker = app(PermissionChecker::class);
        $keysInCompany1 = $checker->effectivePermissionKeys($superadmin, $this->company->id);
        $keysInCompany2 = $checker->effectivePermissionKeys($superadmin, $otherCompany->id);

        $this->assertNotEmpty($keysInCompany1, 'Superadmin must have permissions in company 1');
        $this->assertSame(
            $keysInCompany1,
            $keysInCompany2,
            'Superadmin permission list must be identical regardless of which company is active'
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeCompany(): Company
    {
        self::$seq++;
        return Company::withoutGlobalScope('company')->create([
            'name'                => 'Company '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-03',
            'registration_number' => '01-01-'.str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }
}
