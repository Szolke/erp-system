<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Modules\ModuleDescriptor;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleResolver;
use App\Services\PermissionChecker;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Regression anchors for Phase 2 — module-gated RBAC.
 *
 * Invariants tested:
 *   1. Gate::allows() and effectivePermissionKeys() always agree (module ON and OFF).
 *   2. Superadmin is blocked from module-owned permissions when the module is disabled.
 *   3. module.manage is always accessible — prevents admin lockout if module is toggled off.
 *   4. Superadmin permission cache is per-company (superadmin:{companyId} key prevents cross-company leak).
 *   5. Ungated / cross-cutting permissions survive with all optional modules disabled.
 *   6. EnsureModuleEnabled middleware: 404 when OFF, passes through when ON.
 *   7. Duplicate permission ownership across descriptors throws RuntimeException.
 *   8. Normal user receives only module-enabled permissions from their group grants.
 */
class ModuleGatingTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test starts with fresh scoped instances (PermissionChecker, ModuleResolver)
        // so no in-process cache from a previous test method can interfere.
        app()->forgetScopedInstances();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. Gate ↔ effectivePermissionKeys consistency
    // ══════════════════════════════════════════════════════════════════════════

    public function test_gate_and_effective_keys_agree_when_module_off(): void
    {
        $company    = $this->makeCompany();
        $permission = Permission::create(['key' => 'simplepay.use', 'module' => 'simplepay']);
        // simplepay module not attached to company → disabled

        $user = $this->makeUser();
        $company->users()->attach($user->id);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'Payers']);
        $group->users()->attach($user->id);
        $group->permissions()->attach($permission->id);

        $checker    = app(PermissionChecker::class);
        $gateResult = Gate::forUser($user)->allows('simplepay.use');
        $keyResult  = in_array('simplepay.use', $checker->effectivePermissionKeys($user, $company->id), true);

        $this->assertFalse($gateResult, 'Gate must deny when module is OFF');
        $this->assertFalse($keyResult, 'effectivePermissionKeys must not include key when module is OFF');
        $this->assertSame($gateResult, $keyResult, 'Gate and effectivePermissionKeys must agree');
    }

    public function test_gate_and_effective_keys_agree_when_module_on(): void
    {
        $company    = $this->makeCompany();
        $module     = $this->makeModule('simplepay');
        $this->enableModule($company, $module);
        $permission = Permission::create(['key' => 'simplepay.use', 'module' => 'simplepay']);

        $user = $this->makeUser();
        $company->users()->attach($user->id);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'Payers']);
        $group->users()->attach($user->id);
        $group->permissions()->attach($permission->id);

        $checker    = app(PermissionChecker::class);
        $gateResult = Gate::forUser($user)->allows('simplepay.use');
        $keyResult  = in_array('simplepay.use', $checker->effectivePermissionKeys($user, $company->id), true);

        $this->assertTrue($gateResult, 'Gate must allow when module is ON and user has group grant');
        $this->assertTrue($keyResult, 'effectivePermissionKeys must include key when module is ON');
        $this->assertSame($gateResult, $keyResult, 'Gate and effectivePermissionKeys must agree');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Superadmin gating: disabled module blocks even superadmin
    // ══════════════════════════════════════════════════════════════════════════

    public function test_superadmin_blocked_from_module_owned_key_when_module_off(): void
    {
        $company    = $this->makeCompany();
        Permission::create(['key' => 'invoice.send_nav', 'module' => 'invoice']);
        Permission::create(['key' => 'invoice.create',   'module' => 'invoice']);
        // nav module not attached → disabled; invoicing is core → always enabled

        $superadmin = $this->makeUser(superadmin: true);
        app(CurrentCompany::class)->set($company->id);

        $this->assertFalse(
            Gate::forUser($superadmin)->allows('invoice.send_nav'),
            'Superadmin must NOT receive invoice.send_nav when nav module is OFF'
        );
        $this->assertTrue(
            Gate::forUser($superadmin)->allows('invoice.create'),
            'Superadmin must retain invoice.create (core invoicing module) regardless of nav state'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. module.manage always accessible — prevents admin lockout
    // ══════════════════════════════════════════════════════════════════════════

    public function test_module_manage_always_accessible_for_superadmin_with_no_modules_enabled(): void
    {
        $company    = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        // no optional modules attached

        $superadmin = $this->makeUser(superadmin: true);
        app(CurrentCompany::class)->set($company->id);

        $this->assertTrue(
            Gate::forUser($superadmin)->allows('module.manage'),
            'module.manage must be accessible for superadmin even when all optional modules are OFF'
        );
    }

    public function test_module_manage_always_accessible_for_user_with_grant_and_no_modules_enabled(): void
    {
        $company    = $this->makeCompany();
        $permission = Permission::create(['key' => 'module.manage', 'module' => 'module']);
        // no optional modules attached

        $user = $this->makeUser();
        $company->users()->attach($user->id);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'Module Admins']);
        $group->users()->attach($user->id);
        $group->permissions()->attach($permission->id);

        $checker = app(PermissionChecker::class);

        $this->assertTrue(
            Gate::forUser($user)->allows('module.manage'),
            'module.manage must be accessible via Gate for a user with the grant, even with no modules enabled'
        );
        $this->assertContains(
            'module.manage',
            $checker->effectivePermissionKeys($user, $company->id),
            'module.manage must appear in effectivePermissionKeys even with no optional modules enabled'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. Superadmin cache key is per-company — no cross-company module state leak
    // ══════════════════════════════════════════════════════════════════════════

    public function test_superadmin_cache_key_is_per_company(): void
    {
        // companyA: nav ON; companyB: nav OFF — same PermissionChecker instance must return
        // different results because the cache key includes companyId.
        $companyA  = $this->makeCompany();
        $companyB  = $this->makeCompany();
        $navModule = $this->makeModule('nav');
        $this->enableModule($companyA, $navModule);
        // companyB deliberately not linked to nav module

        Permission::create(['key' => 'invoice.send_nav', 'module' => 'invoice']);

        $superadmin = $this->makeUser(superadmin: true);
        $checker    = app(PermissionChecker::class);

        $keysA = $checker->effectivePermissionKeys($superadmin, $companyA->id);
        $keysB = $checker->effectivePermissionKeys($superadmin, $companyB->id);

        $this->assertContains(
            'invoice.send_nav',
            $keysA,
            'invoice.send_nav must appear for companyA (nav ON)'
        );
        $this->assertNotContains(
            'invoice.send_nav',
            $keysB,
            'invoice.send_nav must NOT appear for companyB (nav OFF) — superadmin cache must not leak between companies'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. Ungated / cross-cutting permissions survive when all optional modules are off
    // ══════════════════════════════════════════════════════════════════════════

    public function test_ungated_permissions_survive_when_all_optional_modules_off(): void
    {
        $company = $this->makeCompany();
        // No optional modules attached

        $ungatedKeys = ['audit.view', 'user.manage', 'payment.view', 'module.manage'];
        foreach ($ungatedKeys as $key) {
            Permission::create(['key' => $key, 'module' => explode('.', $key)[0]]);
        }

        $superadmin = $this->makeUser(superadmin: true);
        app(CurrentCompany::class)->set($company->id);

        $keys = app(PermissionChecker::class)->effectivePermissionKeys($superadmin, $company->id);

        foreach ($ungatedKeys as $key) {
            $this->assertContains($key, $keys, "{$key} must always be present (ungated / cross-cutting permission)");
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 6. EnsureModuleEnabled middleware: 404 when OFF, passes through when ON
    // ══════════════════════════════════════════════════════════════════════════

    public function test_middleware_returns_404_when_simplepay_module_off(): void
    {
        $company    = $this->makeCompany();
        // simplepay module NOT attached to company
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->getJson('/api/company/simplepay')
            ->assertNotFound();
    }

    public function test_middleware_passes_when_simplepay_module_on(): void
    {
        $company   = $this->makeCompany();
        $module    = $this->makeModule('simplepay');
        $this->enableModule($company, $module);

        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->getJson('/api/company/simplepay')
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 7. Duplicate permission ownership throws RuntimeException
    // ══════════════════════════════════════════════════════════════════════════

    public function test_duplicate_permission_ownership_throws_runtime_exception(): void
    {
        $moduleA = new class extends ModuleDescriptor {
            public function key(): string         { return 'fake_a'; }
            public function name(): string        { return 'Fake A'; }
            public function description(): string { return ''; }
            public function version(): string     { return '1.0.0'; }
            public function isCore(): bool        { return false; }
            public function permissions(): array  { return ['shared.action']; }
        };

        $moduleB = new class extends ModuleDescriptor {
            public function key(): string         { return 'fake_b'; }
            public function name(): string        { return 'Fake B'; }
            public function description(): string { return ''; }
            public function version(): string     { return '1.0.0'; }
            public function isCore(): bool        { return false; }
            public function permissions(): array  { return ['shared.action']; }  // duplicate
        };

        // Construct a registry that bypasses config and injects the two conflicting descriptors.
        $fakeRegistry = new class($moduleA, $moduleB) extends ModuleRegistry {
            private array $descs;

            public function __construct(ModuleDescriptor ...$descs)
            {
                // Intentionally skip parent::__construct() — no config read needed.
                $this->descs = $descs;
            }

            public function all(): array { return $this->descs; }
        };

        $resolver = new ModuleResolver($fakeRegistry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/shared\.action/');

        $resolver->permissionToModuleMap();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 8. Normal user: module OFF removes gated permission even with group grant
    // ══════════════════════════════════════════════════════════════════════════

    public function test_normal_user_module_off_excludes_gated_permission_from_group_grant(): void
    {
        $company  = $this->makeCompany();
        // nav module NOT enabled for this company
        $navPerm  = Permission::create(['key' => 'invoice.send_nav', 'module' => 'invoice']);
        $corePerm = Permission::create(['key' => 'invoice.view',     'module' => 'invoice']);

        $user = $this->makeUser();
        $company->users()->attach($user->id);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'Accountants']);
        $group->users()->attach($user->id);
        $group->permissions()->attach([$navPerm->id, $corePerm->id]);

        $keys = app(PermissionChecker::class)->effectivePermissionKeys($user, $company->id);

        $this->assertNotContains(
            'invoice.send_nav',
            $keys,
            'invoice.send_nav must be excluded even with group grant because nav module is OFF'
        );
        $this->assertContains(
            'invoice.view',
            $keys,
            'invoice.view must remain accessible (core invoicing module is always enabled)'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

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

    private function makeUser(bool $superadmin = false): User
    {
        self::$seq++;
        return User::create([
            'name'          => 'User '.self::$seq,
            'email'         => 'user'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function makeModule(string $key, bool $isCore = false): Module
    {
        return Module::create([
            'key'          => $key,
            'name'         => ucfirst($key),
            'description'  => "Test module {$key}",
            'version'      => '1.0.0',
            'is_core'      => $isCore,
            'is_available' => true,
            'sort_order'   => 10,
        ]);
    }

    private function enableModule(Company $company, Module $module): void
    {
        $company->enabledModules()->attach($module->id, ['enabled' => true]);
    }

    private function inCompany(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
