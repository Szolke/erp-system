<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Modules\ModuleDescriptor;
use App\Modules\ModuleRegistry;
use App\Services\PermissionChecker;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 — Module Management API tests.
 *
 * Covers:
 *   1. Authorization: module.manage required for GET and PATCH.
 *   2. Core module protection: PATCH core module → 422.
 *   3. Missing dependency blocks enable: 422 + missing_dependencies field.
 *   4. Active dependent blocks disable: 422 + dependents field.
 *   5. Successful toggle: pivot (enabled, enabled_by, enabled_at) + audit log written.
 *   6. Pivot row survives disable (enabled=false, NOT deleted).
 *   7. No-op idempotency: no audit log when state already matches request.
 *   8. Multi-tenant isolation: enabling in company A must not leak to company B
 *      (GET /api/modules, effectivePermissionKeys, EnsureModuleEnabled all isolated).
 */
class ModuleManagementTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. Authorization
    // ══════════════════════════════════════════════════════════════════════════

    public function test_get_modules_returns_403_without_module_manage(): void
    {
        $company = $this->makeCompany();
        $this->makeModule('simplepay');
        $user = $this->makeUser();
        $user->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($user, $company)
            ->getJson('/api/modules')
            ->assertForbidden();
    }

    public function test_patch_module_returns_403_without_module_manage(): void
    {
        $company = $this->makeCompany();
        $this->makeModule('simplepay');
        $user = $this->makeUser();
        $user->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($user, $company)
            ->patchJson('/api/modules/simplepay', ['enabled' => true])
            ->assertForbidden();
    }

    public function test_superadmin_can_list_modules(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        $this->makeModule('simplepay');
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->getJson('/api/modules')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'key', 'name', 'enabled', 'is_core', 'dependencies']]]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Core module protection
    // ══════════════════════════════════════════════════════════════════════════

    public function test_cannot_toggle_core_module(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        $this->makeModule('invoicing', isCore: true);
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->patchJson('/api/modules/invoicing', ['enabled' => false])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => "A(z) 'invoicing' alap modul nem kapcsolható ki."]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. Missing dependency blocks enable
    //
    // module_a depends on module_b; module_b is not active → 422.
    // Uses a fake registry because real descriptors all depend on 'invoicing' (core),
    // which is always active — we need a dependency on a non-active optional module.
    // ══════════════════════════════════════════════════════════════════════════

    public function test_enable_fails_with_422_when_dependency_not_active(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        $this->makeModule('module_a');
        $this->makeModule('module_b'); // not enabled in company

        $this->withFakeRegistry([
            $this->makeDescriptor('module_a', dependencies: ['module_b']),
            $this->makeDescriptor('module_b'),
        ]);

        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->patchJson('/api/modules/module_a', ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonPath('missing_dependencies.0', 'module_b');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. Active dependent blocks disable
    //
    // dep_user is active and depends on dep_target → disabling dep_target → 422.
    // ══════════════════════════════════════════════════════════════════════════

    public function test_disable_fails_with_422_when_active_module_depends_on_it(): void
    {
        $company  = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        $target    = $this->makeModule('dep_target');
        $dependent = $this->makeModule('dep_user');
        $this->enableModule($company, $target);
        $this->enableModule($company, $dependent);

        $this->withFakeRegistry([
            $this->makeDescriptor('dep_target'),
            $this->makeDescriptor('dep_user', dependencies: ['dep_target']),
        ]);

        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->patchJson('/api/modules/dep_target', ['enabled' => false])
            ->assertUnprocessable()
            ->assertJsonPath('dependents.0', 'dep_user');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. Successful toggle: pivot + audit log
    // ══════════════════════════════════════════════════════════════════════════

    public function test_successful_enable_writes_pivot_and_audit_log(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        $module  = $this->makeModule('simplepay');
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->patchJson('/api/modules/simplepay', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        // Pivot row: enabled=true, enabled_by set, enabled_at set
        $row = DB::table('company_module')
            ->where('company_id', $company->id)
            ->where('module_id', $module->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->enabled);
        $this->assertEquals($superadmin->id, $row->enabled_by);
        $this->assertNotNull($row->enabled_at);

        // Audit log: action, company, user, auditable
        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'module.enabled',
            'company_id'     => $company->id,
            'user_id'        => $superadmin->id,
            'auditable_type' => Module::class,
            'auditable_id'   => $module->id,
        ]);
    }

    public function test_successful_disable_updates_pivot_and_writes_audit_log(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        $module  = $this->makeModule('simplepay');
        $this->enableModule($company, $module);
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->patchJson('/api/modules/simplepay', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'module.disabled',
            'company_id'     => $company->id,
            'user_id'        => $superadmin->id,
            'auditable_type' => Module::class,
            'auditable_id'   => $module->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 6. Pivot row survives disable (enabled=false, NOT deleted)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_disable_keeps_pivot_row_with_enabled_false(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        $module  = $this->makeModule('simplepay');
        $this->enableModule($company, $module);
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->patchJson('/api/modules/simplepay', ['enabled' => false])
            ->assertOk();

        $row = DB::table('company_module')
            ->where('company_id', $company->id)
            ->where('module_id', $module->id)
            ->first();

        $this->assertNotNull($row, 'Pivot row must not be deleted on disable');
        $this->assertFalse((bool) $row->enabled, 'Pivot row must have enabled=false after disable');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 7. No-op idempotency: no audit log when state already matches request
    // ══════════════════════════════════════════════════════════════════════════

    public function test_noop_enable_on_already_enabled_produces_no_audit_log(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        $module  = $this->makeModule('simplepay');
        $this->enableModule($company, $module);
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->patchJson('/api/modules/simplepay', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_noop_disable_on_already_disabled_produces_no_audit_log(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        $this->makeModule('simplepay'); // NOT enabled in company — already off
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        $this->inCompany($superadmin, $company)
            ->patchJson('/api/modules/simplepay', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 8. Multi-tenant isolation — the most critical invariant.
    //
    // Enabling simplepay in company A must NOT affect company B:
    //   a) GET /api/modules for company B still shows simplepay as disabled.
    //   b) effectivePermissionKeys() for company B does NOT include simplepay.use.
    //   c) EnsureModuleEnabled middleware returns 404 for company B.
    // ══════════════════════════════════════════════════════════════════════════

    public function test_module_enabled_in_company_a_is_invisible_to_company_b(): void
    {
        Permission::create(['key' => 'module.manage', 'module' => 'module']);
        Permission::create(['key' => 'simplepay.use',  'module' => 'simplepay']);

        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $module   = $this->makeModule('simplepay');

        // Only company A has simplepay enabled
        $this->enableModule($companyA, $module);

        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($companyA->id, ['is_default' => true]);
        $superadmin->companies()->attach($companyB->id);

        // a) GET /api/modules — company A: enabled; company B: disabled
        $this->inCompany($superadmin, $companyA)
            ->getJson('/api/modules')
            ->assertOk()
            ->assertJsonFragment(['key' => 'simplepay', 'enabled' => true]);

        app()->forgetScopedInstances();

        $this->inCompany($superadmin, $companyB)
            ->getJson('/api/modules')
            ->assertOk()
            ->assertJsonFragment(['key' => 'simplepay', 'enabled' => false]);

        // b) effectivePermissionKeys — simplepay.use must NOT appear for company B
        app()->forgetScopedInstances();
        app(CurrentCompany::class)->set($companyB->id);
        $keysB = app(PermissionChecker::class)->effectivePermissionKeys($superadmin, $companyB->id);
        $this->assertNotContains(
            'simplepay.use',
            $keysB,
            'simplepay.use must NOT be in effectivePermissionKeys for company B (module OFF)'
        );

        // c) EnsureModuleEnabled — company B must still get 404
        app()->forgetScopedInstances();
        $this->inCompany($superadmin, $companyB)
            ->getJson('/api/company/simplepay')
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function makeCompany(): Company
    {
        self::$seq++;
        return Company::withoutGlobalScope('company')->create([
            'name'                => 'Company ' . self::$seq,
            'tax_number'          => '1234567' . self::$seq . '-2-03',
            'registration_number' => '01-01-' . str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
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
            'name'          => 'User ' . self::$seq,
            'email'         => 'user' . self::$seq . '@example.com',
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

    /**
     * Binds a fake ModuleRegistry into the container for the current test.
     * Overrides all() / find() / coreKeys() with test-controlled data.
     * Scoped singletons (ModuleResolver, PermissionChecker) are flushed so
     * they rebuild against the fake registry on the next resolution.
     *
     * Safe to call once per test — app container is recreated per test method.
     */
    private function withFakeRegistry(array $descriptors): void
    {
        $registry = new class ($descriptors) extends ModuleRegistry {
            private array $descs;

            public function __construct(array $descs)
            {
                // Skip parent::__construct() — no config/module loading needed.
                $this->descs = $descs;
            }

            public function all(): array { return $this->descs; }

            public function coreKeys(): array { return []; }

            public function find(string $key): ?ModuleDescriptor
            {
                foreach ($this->descs as $d) {
                    if ($d->key() === $key) return $d;
                }
                return null;
            }
        };

        $this->instance(ModuleRegistry::class, $registry);
        app()->forgetScopedInstances();
    }

    /**
     * Creates an anonymous ModuleDescriptor for test use.
     * The constructor arguments are captured via promoted properties on the
     * anonymous class — valid PHP 8+ syntax.
     */
    private function makeDescriptor(string $key, array $dependencies = []): ModuleDescriptor
    {
        return new class ($key, $dependencies) extends ModuleDescriptor {
            public function __construct(
                private readonly string $k,
                private readonly array  $deps,
            ) {}

            public function key(): string         { return $this->k; }
            public function name(): string        { return ucfirst($this->k); }
            public function description(): string { return ''; }
            public function version(): string     { return '1.0.0'; }
            public function isCore(): bool        { return false; }
            public function permissions(): array  { return []; }
            public function dependencies(): array { return $this->deps; }
        };
    }
}
