<?php

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\Company;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/me — a PermissionChecker::effectivePermissionKeys() útvonal a
 * report.view/report.export kulcsokra. Ez a frontend can() forrása, KÜLÖN
 * kód a Gate::before-tól (ami a backend authorize()-t szolgálja ki) — ha
 * csak az egyikbe kerülne be a modul-gating vagy a jog, a másik útvonalon
 * eltérő (hibás) eredmény jönne vissza. Ld. docs/progress.md, Architekturális
 * konvenciók: "Jogosultság-feloldás KÉT úton történik".
 */
class ReportPermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        self::$seq++;
        $this->company = Company::create([
            'name' => 'Me Jog Kft. '.self::$seq,
            'tax_number' => '7777777'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'Me u. 1.',
            'base_currency' => 'HUF',
        ]);

        $this->module = Module::create([
            'key' => 'reports', 'name' => 'Kimutatások', 'description' => 'test',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 20,
        ]);

        Permission::firstOrCreate(['key' => 'report.view'], ['module' => 'report', 'description' => 'report.view', 'is_sensitive' => false]);
        Permission::firstOrCreate(['key' => 'report.export'], ['module' => 'report', 'description' => 'report.export', 'is_sensitive' => false]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_user_with_permission_and_module_enabled_sees_report_view_in_effective_permissions(): void
    {
        $this->enableModule();
        $user = $this->makeUserWithOverride('report.view');

        $permissions = $this->meAs($user)['permissions'];

        $this->assertContains('report.view', $permissions);
    }

    public function test_user_without_the_permission_does_not_see_report_view(): void
    {
        $this->enableModule();
        $user = $this->makeUserWithOverride(null);

        $permissions = $this->meAs($user)['permissions'];

        $this->assertNotContains('report.view', $permissions);
    }

    public function test_disabled_module_hides_report_view_even_with_the_permission_granted(): void
    {
        // A modul szándékosan NINCS bekapcsolva — a jog önmagában nem elég.
        $user = $this->makeUserWithOverride('report.view');

        $permissions = $this->meAs($user)['permissions'];

        $this->assertNotContains('report.view', $permissions, 'Kikapcsolt modulnál a jog nem szivároghat át az effectivePermissionKeys()-en');
    }

    public function test_superadmin_sees_both_report_keys_when_module_enabled(): void
    {
        $this->enableModule();
        $superadmin = $this->makeUser(superadmin: true);

        $permissions = $this->meAs($superadmin)['permissions'];

        $this->assertContains('report.view', $permissions);
        $this->assertContains('report.export', $permissions);
    }

    public function test_superadmin_does_not_see_report_keys_when_module_disabled(): void
    {
        // A szuperadmin sem kap a kikapcsolt modulhoz tartozó kulcsokat — a
        // ModuleResolver::filterPermissionKeys() a szuperadmin-ágat is szűri.
        $superadmin = $this->makeUser(superadmin: true);

        $permissions = $this->meAs($superadmin)['permissions'];

        $this->assertNotContains('report.view', $permissions);
        $this->assertNotContains('report.export', $permissions);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function enableModule(): void
    {
        $this->company->enabledModules()->attach($this->module->id, ['enabled' => true]);
    }

    private function makeUser(bool $superadmin = false): User
    {
        self::$seq++;
        $user = User::create([
            'name' => 'Me User '.self::$seq,
            'email' => 'me.user.'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
        $user->companies()->attach($this->company->id, ['is_default' => true]);

        return $user;
    }

    private function makeUserWithOverride(?string $permissionKey): User
    {
        $user = $this->makeUser();

        if ($permissionKey !== null) {
            $permission = Permission::where('key', $permissionKey)->firstOrFail();
            $user->permissionOverrides()->create([
                'company_id' => $this->company->id,
                'permission_id' => $permission->id,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return $user;
    }

    private function meAs(User $user): array
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/me')
            ->assertOk()
            ->json();
    }
}
