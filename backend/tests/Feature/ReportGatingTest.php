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
 * Jogosultság- és modul-gating a /api/reports/* végpontokon: report.view a
 * négy JSON-végponthoz, report.export az exporthoz (a kettő független), a
 * 'reports' modul kikapcsolt állapotában 403 (NEM 404 — l. ReportController
 * docblock), és alap multi-company izoláció.
 */
class ReportGatingTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();
        // RefreshDatabase a Postgres-t resetálja, a Redist NEM — a company_id
        // sorozat újra kezdődhet a következő teszt-futtatáskor, és egy korábbi
        // futásból maradt cache-bejegyzés (company_id + paraméterek alapján
        // kulcsolva) áthallást okozna a riport-eredményekbe.
        \Illuminate\Support\Facades\Cache::store('redis')->tags(['reports'])->flush();

        $this->company = $this->makeCompany();
        $this->module = Module::create([
            'key' => 'reports', 'name' => 'Kimutatások', 'description' => 'test',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private const JSON_ENDPOINTS = [
        '/api/reports/invoices?from=2026-01&to=2026-01',
        '/api/reports/products?from=2026-01&to=2026-01',
        '/api/reports/receivables-aging',
        '/api/reports/vat-summary?from=2026-01&to=2026-01',
    ];

    public function test_unauthenticated_request_returns_401(): void
    {
        foreach (self::JSON_ENDPOINTS as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }
        $this->getJson('/api/reports/invoices/export?from=2026-01&to=2026-01')->assertUnauthorized();
    }

    public function test_module_disabled_returns_403_not_404(): void
    {
        // A modul szándékosan NINCS bekapcsolva ehhez a céghez.
        $user = $this->makeUserWithPermissions(['report.view', 'report.export']);

        foreach (self::JSON_ENDPOINTS as $endpoint) {
            $this->asUser($user)->getJson($endpoint)->assertForbidden();
        }
        $this->asUser($user)->getJson('/api/reports/invoices/export?from=2026-01&to=2026-01')->assertForbidden();
    }

    public function test_user_without_report_view_gets_403_on_json_endpoints(): void
    {
        $this->enableModule();
        $user = $this->makeUserWithPermissions([]); // se report.view, se report.export

        foreach (self::JSON_ENDPOINTS as $endpoint) {
            $this->asUser($user)->getJson($endpoint)->assertForbidden();
        }
    }

    public function test_export_requires_report_export_even_with_report_view(): void
    {
        $this->enableModule();
        $user = $this->makeUserWithPermissions(['report.view']); // NINCS report.export

        $this->asUser($user)
            ->getJson('/api/reports/invoices/export?from=2026-01&to=2026-01')
            ->assertForbidden();
    }

    public function test_report_view_alone_is_enough_for_json_endpoints_without_export(): void
    {
        $this->enableModule();
        $user = $this->makeUserWithPermissions(['report.view']);

        foreach (self::JSON_ENDPOINTS as $endpoint) {
            $this->asUser($user)->getJson($endpoint)->assertOk();
        }
    }

    public function test_user_with_both_permissions_can_export(): void
    {
        $this->enableModule();
        $user = $this->makeUserWithPermissions(['report.view', 'report.export']);

        $this->asUser($user)
            ->getJson('/api/reports/invoices/export?from=2026-01&to=2026-01')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_unknown_export_report_name_returns_404(): void
    {
        $this->enableModule();
        $user = $this->makeUserWithPermissions(['report.export']);

        $this->asUser($user)
            ->getJson('/api/reports/nonexistent/export?from=2026-01&to=2026-01')
            ->assertNotFound();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function enableModule(): void
    {
        $this->company->enabledModules()->attach($this->module->id, ['enabled' => true]);
    }

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::withoutGlobalScope('company')->create([
            'name' => 'Report Gating Kft. '.self::$seq,
            'tax_number' => '5555555'.self::$seq.'-2-42',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000',
            'city' => 'Budapest',
            'address_line' => 'Riport u. 1.',
            'base_currency' => 'HUF',
        ]);
    }

    private function makeUserWithPermissions(array $permissionKeys): User
    {
        self::$seq++;
        $user = User::create([
            'name' => 'Report User '.self::$seq,
            'email' => 'report.user.'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => false,
        ]);
        $user->companies()->attach($this->company->id, ['is_default' => true]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['module' => 'report', 'description' => $key, 'is_sensitive' => false]
            );
            $user->permissionOverrides()->create([
                'company_id' => $this->company->id,
                'permission_id' => $permission->id,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return $user;
    }

    private function asUser(User $user): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }
}
