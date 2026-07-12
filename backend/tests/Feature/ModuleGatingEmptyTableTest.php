<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Modules\ModuleResolver;
use App\Services\PermissionChecker;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CONTRACT TEST — module gating is FAIL-CLOSED with an empty modules table.
 *
 * The ModuleRegistry reads descriptors from config/modules.php (zero DB dependency).
 * ModuleResolver::enabledModuleKeys() merges core keys (from code) with pivot rows (DB).
 * With an empty `modules` table the pivot query returns [] → only core keys remain active.
 *
 * THIS TEST LOCKS THAT INVARIANT. If any assertion here breaks, the gating has
 * become fail-OPEN. The fix is to restore the fail-closed invariant, NOT to
 * change this test.
 *
 * Architecture note: `erp:sync-modules` fills the catalog for the admin UI and
 * pivot FK capability — it does NOT affect security gating. The gating is correct
 * even without it running.
 */
class ModuleGatingEmptyTableTest extends TestCase
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
    // 1. enabledModuleKeys() returns exactly the 4 core keys from code
    //    (no DB rows needed — config/modules.php is the authority)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_enabled_module_keys_returns_only_core_keys_when_modules_table_is_empty(): void
    {
        $company  = $this->makeCompany();
        $resolver = app(ModuleResolver::class);

        $enabled = $resolver->enabledModuleKeys($company->id);

        // The four core descriptors in config/modules.php.
        // Optional keys (nav, simplepay, ntak) must NOT appear.
        $this->assertEqualsCanonicalizing(
            ['invoicing', 'receipts', 'partners', 'products'],
            $enabled,
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. isAllowed() returns false for optional-module-gated permissions
    // ══════════════════════════════════════════════════════════════════════════

    public function test_is_allowed_returns_false_for_optional_module_permissions_with_empty_table(): void
    {
        $company  = $this->makeCompany();
        $resolver = app(ModuleResolver::class);

        $this->assertFalse(
            $resolver->isAllowed('invoice.send_nav', $company->id),
            'invoice.send_nav is gated by the nav module — must be blocked when modules table is empty',
        );
        $this->assertFalse(
            $resolver->isAllowed('simplepay.use', $company->id),
            'simplepay.use is gated by the simplepay module — must be blocked when modules table is empty',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. Gate::before blocks optional-module permissions even for superadmin
    //    (Gate::before runs isAllowed() BEFORE the superadmin bypass)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_gate_blocks_optional_module_permission_for_superadmin_with_empty_table(): void
    {
        $company    = $this->makeCompany();
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        app(CurrentCompany::class)->set($company->id);
        $this->actingAs($superadmin);

        $this->assertFalse(
            $superadmin->can('invoice.send_nav'),
            'Gate::before must short-circuit with false — superadmin bypass is never reached when module is OFF',
        );
        $this->assertFalse(
            $superadmin->can('simplepay.use'),
            'Gate::before must short-circuit with false — superadmin bypass is never reached when module is OFF',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. effectivePermissionKeys() exposes core permissions but strips optional
    //    module permissions from the result (even for superadmin)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_effective_permission_keys_excludes_optional_module_permissions_with_empty_table(): void
    {
        // Create permission rows so the resolver has something to filter.
        Permission::create(['key' => 'invoice.view',     'module' => 'invoicing']);
        Permission::create(['key' => 'invoice.send_nav', 'module' => 'nav']);
        Permission::create(['key' => 'simplepay.use',    'module' => 'simplepay']);

        $company    = $this->makeCompany();
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        app(CurrentCompany::class)->set($company->id);

        $keys = app(PermissionChecker::class)->effectivePermissionKeys($superadmin, $company->id);

        $this->assertContains('invoice.view', $keys,
            'Core module permission must be visible in effectivePermissionKeys');
        $this->assertNotContains('invoice.send_nav', $keys,
            'NAV permission must be blocked — nav module is OFF with empty modules table');
        $this->assertNotContains('simplepay.use', $keys,
            'SimplePay permission must be blocked — simplepay module is OFF with empty modules table');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. EnsureModuleEnabled middleware returns 404 for a simplepay-gated route
    // ══════════════════════════════════════════════════════════════════════════

    public function test_ensure_module_enabled_middleware_returns_404_with_empty_modules_table(): void
    {
        $company    = $this->makeCompany();
        $superadmin = $this->makeUser(superadmin: true);
        $superadmin->companies()->attach($company->id, ['is_default' => true]);

        // GET /api/company/simplepay is protected by middleware('module:simplepay').
        // With an empty modules table, simplepay is not in enabledModuleKeys → 404.
        $this->inCompany($superadmin, $company)
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

    private function inCompany(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
