<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Assets module — scaffolding-only coverage (no model/table/API yet, see progress.md).
 *
 * Covers:
 *   1. Registry registration (key, isCore, declared permissions)
 *   2. Gate ↔ effectivePermissionKeys agreement, module ON and OFF
 *   3. Superadmin also blocked when the module is disabled (gating applies to everyone)
 */
class AssetModuleTest extends TestCase
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
    // 1. Registry registration
    // ══════════════════════════════════════════════════════════════════════════

    public function test_assets_module_registered_with_correct_metadata(): void
    {
        $descriptor = app(ModuleRegistry::class)->find('assets');

        $this->assertNotNull($descriptor, 'assets module must be registered in the ModuleRegistry');
        $this->assertSame('assets', $descriptor->key());
        $this->assertFalse($descriptor->isCore(), 'assets module must be optional, not core');
        $this->assertSame([], $descriptor->dependencies());
        $this->assertSame(
            ['asset.view', 'asset.create', 'asset.edit', 'asset.delete'],
            $descriptor->permissions()
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Gate ↔ effectivePermissionKeys agreement
    // ══════════════════════════════════════════════════════════════════════════

    public function test_gate_and_effective_keys_agree_when_assets_module_off(): void
    {
        $company    = $this->makeCompany();
        $permission = Permission::create(['key' => 'asset.view', 'module' => 'asset']);
        // assets module not attached to company → disabled

        $user = $this->makeUser();
        $company->users()->attach($user->id);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'Warehouse']);
        $group->users()->attach($user->id);
        $group->permissions()->attach($permission->id);

        $checker    = app(\App\Services\PermissionChecker::class);
        $gateResult = Gate::forUser($user)->allows('asset.view');
        $keyResult  = in_array('asset.view', $checker->effectivePermissionKeys($user, $company->id), true);

        $this->assertFalse($gateResult, 'Gate must deny when assets module is OFF');
        $this->assertFalse($keyResult, 'effectivePermissionKeys must not include key when assets module is OFF');
        $this->assertSame($gateResult, $keyResult, 'Gate and effectivePermissionKeys must agree');
    }

    public function test_gate_and_effective_keys_agree_when_assets_module_on(): void
    {
        $company    = $this->makeCompany();
        $module     = $this->makeModule('assets');
        $this->enableModule($company, $module);
        $permission = Permission::create(['key' => 'asset.view', 'module' => 'asset']);

        $user = $this->makeUser();
        $company->users()->attach($user->id);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'Warehouse']);
        $group->users()->attach($user->id);
        $group->permissions()->attach($permission->id);

        $checker    = app(\App\Services\PermissionChecker::class);
        $gateResult = Gate::forUser($user)->allows('asset.view');
        $keyResult  = in_array('asset.view', $checker->effectivePermissionKeys($user, $company->id), true);

        $this->assertTrue($gateResult, 'Gate must allow when assets module is ON and user has group grant');
        $this->assertTrue($keyResult, 'effectivePermissionKeys must include key when assets module is ON');
        $this->assertSame($gateResult, $keyResult, 'Gate and effectivePermissionKeys must agree');
    }

    public function test_all_four_asset_permissions_present_when_module_on(): void
    {
        $company = $this->makeCompany();
        $module  = $this->makeModule('assets');
        $this->enableModule($company, $module);

        foreach (['asset.view', 'asset.create', 'asset.edit', 'asset.delete'] as $key) {
            Permission::create(['key' => $key, 'module' => 'asset']);
        }

        $superadmin = $this->makeUser(superadmin: true);
        app(CurrentCompany::class)->set($company->id);

        $keys = app(\App\Services\PermissionChecker::class)->effectivePermissionKeys($superadmin, $company->id);

        $this->assertContains('asset.view',   $keys);
        $this->assertContains('asset.create', $keys);
        $this->assertContains('asset.edit',   $keys);
        $this->assertContains('asset.delete', $keys);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. Superadmin gating: disabled module blocks even superadmin
    // ══════════════════════════════════════════════════════════════════════════

    public function test_superadmin_blocked_from_asset_permission_when_module_off(): void
    {
        $company = $this->makeCompany();
        Permission::create(['key' => 'asset.view', 'module' => 'asset']);
        // assets module not attached → disabled

        $superadmin = $this->makeUser(superadmin: true);
        app(CurrentCompany::class)->set($company->id);

        $this->assertFalse(
            Gate::forUser($superadmin)->allows('asset.view'),
            'Superadmin must NOT receive asset.view when assets module is OFF'
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
            'email'         => 'user'.self::$seq.'@example.com',
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
