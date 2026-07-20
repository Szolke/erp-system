<?php

namespace Tests\Feature;

use App\Enums\EnyugtaMode;
use App\Models\Company;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Services\Enyugta\EnyugtaClientInterface;
use App\Services\Enyugta\HttpEnyugtaClient;
use App\Services\Enyugta\MockEnyugtaClient;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * eNyugta modul — 1. fázis (scaffolding + RBAC-bedrótozás) coverage.
 *
 * Fedi:
 *   1. Registry-regisztráció (kulcs, isCore, dependencies, jogkulcsok)
 *   2. Gate ↔ effectivePermissionKeys egyezés, modul BE/KI
 *   3. Superadmin is blokkolva, ha a modul ki van kapcsolva
 *   4. EnyugtaClientInterface feloldása mode szerint (container binding)
 */
class EnyugtaModuleTest extends TestCase
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

    // ══════════════════════════════════════════════════════════════════════
    // 1. Registry registration
    // ══════════════════════════════════════════════════════════════════════

    public function test_enyugta_module_registered_with_correct_metadata(): void
    {
        $descriptor = app(ModuleRegistry::class)->find('enyugta');

        $this->assertNotNull($descriptor, 'enyugta module must be registered in the ModuleRegistry');
        $this->assertSame('enyugta', $descriptor->key());
        $this->assertFalse($descriptor->isCore(), 'enyugta module must be optional, not core');
        $this->assertSame(['receipts'], $descriptor->dependencies());
        $this->assertSame(
            ['enyugta.view', 'enyugta.manage', 'enyugta.submit'],
            $descriptor->permissions()
        );
    }

    /**
     * A receipts modul ma core (ReceiptsModule::isCore() === true), tehát ez
     * a függőség a mai állapotban mindig automatikusan teljesül — ez a teszt
     * ezt a tényleges viselkedést dokumentálja, nem a "hiányzó függőség"
     * ágat (az jelenleg nem elérhető, mert a receipts sosem kapcsolható ki).
     */
    public function test_enyugta_dependency_on_receipts_is_always_satisfied_because_receipts_is_core(): void
    {
        $registry = app(ModuleRegistry::class);
        $this->assertContains('receipts', $registry->coreKeys());
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. Gate ↔ effectivePermissionKeys agreement
    // ══════════════════════════════════════════════════════════════════════

    public function test_gate_and_effective_keys_agree_when_enyugta_module_off(): void
    {
        $company    = $this->makeCompany();
        $permission = Permission::create(['key' => 'enyugta.view', 'module' => 'enyugta']);
        // enyugta module not attached to company → disabled

        $user = $this->makeUser();
        $company->users()->attach($user->id);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'eNyugta']);
        $group->users()->attach($user->id);
        $group->permissions()->attach($permission->id);

        $checker    = app(\App\Services\PermissionChecker::class);
        $gateResult = Gate::forUser($user)->allows('enyugta.view');
        $keyResult  = in_array('enyugta.view', $checker->effectivePermissionKeys($user, $company->id), true);

        $this->assertFalse($gateResult, 'Gate must deny when enyugta module is OFF');
        $this->assertFalse($keyResult, 'effectivePermissionKeys must not include key when enyugta module is OFF');
        $this->assertSame($gateResult, $keyResult, 'Gate and effectivePermissionKeys must agree');
    }

    public function test_gate_and_effective_keys_agree_when_enyugta_module_on(): void
    {
        $company    = $this->makeCompany();
        $module     = $this->makeModule('enyugta');
        $this->enableModule($company, $module);
        $permission = Permission::create(['key' => 'enyugta.view', 'module' => 'enyugta']);

        $user = $this->makeUser();
        $company->users()->attach($user->id);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'eNyugta']);
        $group->users()->attach($user->id);
        $group->permissions()->attach($permission->id);

        $checker    = app(\App\Services\PermissionChecker::class);
        $gateResult = Gate::forUser($user)->allows('enyugta.view');
        $keyResult  = in_array('enyugta.view', $checker->effectivePermissionKeys($user, $company->id), true);

        $this->assertTrue($gateResult, 'Gate must allow when enyugta module is ON and user has group grant');
        $this->assertTrue($keyResult, 'effectivePermissionKeys must include key when enyugta module is ON');
        $this->assertSame($gateResult, $keyResult, 'Gate and effectivePermissionKeys must agree');
    }

    public function test_all_three_enyugta_permissions_present_when_module_on(): void
    {
        $company = $this->makeCompany();
        $module  = $this->makeModule('enyugta');
        $this->enableModule($company, $module);

        foreach (['enyugta.view', 'enyugta.manage', 'enyugta.submit'] as $key) {
            Permission::create(['key' => $key, 'module' => 'enyugta']);
        }

        $superadmin = $this->makeUser(superadmin: true);
        app(CurrentCompany::class)->set($company->id);

        $keys = app(\App\Services\PermissionChecker::class)->effectivePermissionKeys($superadmin, $company->id);

        $this->assertContains('enyugta.view',   $keys);
        $this->assertContains('enyugta.manage', $keys);
        $this->assertContains('enyugta.submit', $keys);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. Superadmin gating: disabled module blocks even superadmin
    // ══════════════════════════════════════════════════════════════════════

    public function test_superadmin_blocked_from_enyugta_permission_when_module_off(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'enyugta.manage', 'module' => 'enyugta']);
        // enyugta module not attached → disabled

        $superadmin = $this->makeUser(superadmin: true);
        app(CurrentCompany::class)->set($company->id);

        $this->assertFalse(
            Gate::forUser($superadmin)->allows('enyugta.manage'),
            'Superadmin must NOT receive enyugta.manage when enyugta module is OFF'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. EnyugtaClientInterface container binding, mode szerint
    // ══════════════════════════════════════════════════════════════════════

    public function test_container_resolves_mock_client_by_default(): void
    {
        // phpunit.xml nem állít be ENYUGTA_DEFAULT_MODE-ot → config('erp.enyugta.default_mode')
        // az .env.example alapértelmezésére ('mock') esik vissza.
        $client = app(EnyugtaClientInterface::class);

        $this->assertInstanceOf(MockEnyugtaClient::class, $client);
    }

    public function test_container_resolves_http_client_when_mode_configured_to_test(): void
    {
        config(['erp.enyugta.default_mode' => 'test']);
        app()->forgetScopedInstances();

        $client = app(EnyugtaClientInterface::class);

        $this->assertInstanceOf(HttpEnyugtaClient::class, $client);
    }

    public function test_mock_client_returns_fixed_small_realistic_list(): void
    {
        $client = new MockEnyugtaClient();

        $categories = $client->fetchVatCategories();

        $this->assertIsArray($categories);
        $this->assertNotEmpty($categories);
        // Egyszerű string-lista (kategórianevek), NEM asszociatív tömb kóddal —
        // a NAV válasza is csak neveket ad (l. jegyzet 2.1.9.2).
        $this->assertSame(array_values($categories), $categories);
        foreach ($categories as $name) {
            $this->assertIsString($name);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::withoutGlobalScope('company')->create([
            'name'                => 'Company '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-04',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
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
            'email'         => 'enyugta.user'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function makeModule(string $key): Module
    {
        return Module::create([
            'key'          => $key,
            'name'         => ucfirst($key),
            'description'  => "Test module {$key}",
            'version'      => '1.0.0',
            'is_core'      => false,
            'is_available' => true,
            'sort_order'   => 10,
        ]);
    }

    private function enableModule(Company $company, Module $module): void
    {
        $company->enabledModules()->attach($module->id, ['enabled' => true]);
    }
}
