<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Module;
use App\Models\User;
use App\Models\VatRate;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AuditLogger kiterjesztése a master data (HasBlameable) controllerekre —
 * l. app/Services/AuditLogger.php::logChange() és docs/progress.md.
 *
 * Minden törzsadat-modellhez KÜLÖN teszt, mert az instrumentálás controllerenkénti
 * hívás (nem egy közös trait), a lefedettség így per-call-site igazolt — a
 * BlameableTest (created_by/updated_by) ezzel szemben egy reprezentatív modellel
 * (Partner) elég, mert ott a trait modellfüggetlen.
 */
class MasterDataAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->company = $this->makeCompany();
        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Partner
    // ══════════════════════════════════════════════════════════════════════════

    public function test_partner_create_writes_audit_log(): void
    {
        $response = $this->asAdmin()->postJson('/api/partners', $this->partnerPayload());
        $response->assertCreated();

        $log = AuditLog::where('action', 'partner.create')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($log);
        $this->assertNull($log->old_values);
        $this->assertSame('Teszt Partner', $log->new_values['name']);
    }

    public function test_partner_update_writes_audit_log_with_before_after(): void
    {
        $partner = $this->asAdmin()->postJson('/api/partners', $this->partnerPayload())->json('data');

        $this->asAdmin()->putJson("/api/partners/{$partner['id']}", $this->partnerPayload(['name' => 'Módosított Partner']))
            ->assertOk();

        $log = AuditLog::where('action', 'partner.update')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Teszt Partner', $log->old_values['name']);
        $this->assertSame('Módosított Partner', $log->new_values['name']);
    }

    public function test_partner_delete_writes_audit_log(): void
    {
        $partner = $this->asAdmin()->postJson('/api/partners', $this->partnerPayload())->json('data');

        $this->asAdmin()->deleteJson("/api/partners/{$partner['id']}")->assertNoContent();

        $log = AuditLog::where('action', 'partner.delete')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Teszt Partner', $log->old_values['name']);
        $this->assertNull($log->new_values);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Product
    // ══════════════════════════════════════════════════════════════════════════

    public function test_product_create_update_delete_write_audit_logs(): void
    {
        $vatRate = VatRate::create(['name' => 'ÁFA 27%', 'rate_percent' => 27, 'nav_code' => '27', 'is_active' => true]);

        $product = $this->asAdmin()->postJson('/api/products', $this->productPayload($vatRate->id))->json('data');
        $this->assertNotNull(
            AuditLog::where('action', 'product.create')->where('company_id', $this->company->id)->first()
        );

        $this->asAdmin()->putJson("/api/products/{$product['id']}", $this->productPayload($vatRate->id, ['name' => 'Módosított Termék']))
            ->assertOk();
        $updateLog = AuditLog::where('action', 'product.update')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($updateLog);
        $this->assertSame('Teszt Termék', $updateLog->old_values['name']);
        $this->assertSame('Módosított Termék', $updateLog->new_values['name']);

        $this->asAdmin()->deleteJson("/api/products/{$product['id']}")->assertNoContent();
        $this->assertNotNull(
            AuditLog::where('action', 'product.delete')->where('company_id', $this->company->id)->first()
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SalesGroup (module-gated)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_sales_group_create_update_delete_write_audit_logs(): void
    {
        $this->enableModule('sales_group');
        $this->company->update(['group_prefix' => 'TST']);

        $group = $this->asAdmin()->postJson('/api/sales-groups', ['name' => 'Észak'])->json('data');
        $this->assertNotNull(
            AuditLog::where('action', 'sales_group.create')->where('company_id', $this->company->id)->first()
        );

        $this->asAdmin()->putJson("/api/sales-groups/{$group['id']}", ['name' => 'Dél'])->assertOk();
        $updateLog = AuditLog::where('action', 'sales_group.update')->where('company_id', $this->company->id)->first();
        $this->assertSame('Észak', $updateLog->old_values['name']);
        $this->assertSame('Dél', $updateLog->new_values['name']);

        $this->asAdmin()->deleteJson("/api/sales-groups/{$group['id']}")->assertNoContent();
        $this->assertNotNull(
            AuditLog::where('action', 'sales_group.delete')->where('company_id', $this->company->id)->first()
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Group (RBAC csoport)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_group_create_update_delete_write_audit_logs(): void
    {
        $group = $this->asAdmin()->postJson('/api/groups', ['name' => 'Csoport A'])->json();
        $this->assertNotNull(
            AuditLog::where('action', 'group.create')->where('company_id', $this->company->id)->first()
        );

        $this->asAdmin()->putJson("/api/groups/{$group['id']}", ['name' => 'Csoport B'])->assertOk();
        $updateLog = AuditLog::where('action', 'group.update')->where('company_id', $this->company->id)->first();
        $this->assertSame('Csoport A', $updateLog->old_values['name']);
        $this->assertSame('Csoport B', $updateLog->new_values['name']);

        $this->asAdmin()->deleteJson("/api/groups/{$group['id']}")->assertNoContent();
        $this->assertNotNull(
            AuditLog::where('action', 'group.delete')->where('company_id', $this->company->id)->first()
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // JobPosition
    // ══════════════════════════════════════════════════════════════════════════

    public function test_job_position_create_update_delete_write_audit_logs(): void
    {
        $jobPosition = $this->asAdmin()->postJson('/api/job-positions', ['name' => 'Könyvelő'])->json('data');
        $this->assertNotNull(
            AuditLog::where('action', 'job_position.create')->where('company_id', $this->company->id)->first()
        );

        $this->asAdmin()->putJson("/api/job-positions/{$jobPosition['id']}", ['name' => 'Főkönyvelő'])->assertOk();
        $updateLog = AuditLog::where('action', 'job_position.update')->where('company_id', $this->company->id)->first();
        $this->assertSame('Könyvelő', $updateLog->old_values['name']);
        $this->assertSame('Főkönyvelő', $updateLog->new_values['name']);

        $this->asAdmin()->deleteJson("/api/job-positions/{$jobPosition['id']}")->assertNoContent();
        $this->assertNotNull(
            AuditLog::where('action', 'job_position.delete')->where('company_id', $this->company->id)->first()
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Asset + AssetType (module-gated)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_asset_type_and_asset_create_update_delete_write_audit_logs(): void
    {
        $this->enableModule('assets');
        $this->company->update(['group_prefix' => 'TST']);

        $assetType = $this->asAdmin()->postJson('/api/asset-types', ['code' => 'LAPTOP', 'name' => 'Laptop'])->json('data');
        $this->assertNotNull(
            AuditLog::where('action', 'asset_type.create')->where('company_id', $this->company->id)->first()
        );

        $assetResponse = $this->asAdmin()->postJson('/api/assets', [
            'serial_number' => 'SN-001',
            'asset_type_id' => $assetType['id'],
        ]);
        $assetResponse->assertCreated();
        $asset = $assetResponse->json('data');
        $this->assertNotNull(
            AuditLog::where('action', 'asset.create')->where('company_id', $this->company->id)->first()
        );

        $this->asAdmin()->putJson("/api/assets/{$asset['id']}", ['serial_number' => 'SN-002', 'status' => 'active'])
            ->assertOk();
        $updateLog = AuditLog::where('action', 'asset.update')->where('company_id', $this->company->id)->first();
        $this->assertSame('SN-001', $updateLog->old_values['serial_number']);
        $this->assertSame('SN-002', $updateLog->new_values['serial_number']);

        $this->asAdmin()->deleteJson("/api/assets/{$asset['id']}")->assertNoContent();
        $this->assertNotNull(
            AuditLog::where('action', 'asset.delete')->where('company_id', $this->company->id)->first()
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DocumentSeries (csak update van route-olva)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_document_series_update_writes_audit_log(): void
    {
        $series = \App\Models\DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $this->company->id,
            'document_type' => \App\Enums\DocumentType::Invoice,
            'prefix' => 'SZ',
            'reset_yearly' => true,
            'next_number' => 1,
        ]);

        $this->asAdmin()->putJson("/api/settings/document-series/{$series->id}", [
            'prefix' => 'ZZZ',
            'reset_yearly' => true,
        ])->assertOk();

        $log = AuditLog::where('action', 'document_series.update')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('SZ', $log->old_values['prefix']);
        $this->assertSame('ZZZ', $log->new_values['prefix']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // User — create/update + jelszó-maszkolás (KRITIKUS érzékeny-mező teszt)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_user_create_writes_audit_log_without_password_hash(): void
    {
        $response = $this->asAdmin()->postJson('/api/users', [
            'name' => 'Új Felhasználó',
            'email' => 'uj.felhasznalo@example.com',
            'password' => 'sup3r-secret-pw',
        ]);
        $response->assertCreated();

        $log = AuditLog::where('action', 'user.create')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Új Felhasználó', $log->new_values['name']);

        // A jelszó (sem plaintext, sem hash formában) nem szerepelhet az audit-logban.
        $this->assertArrayNotHasKey('password', $log->new_values);
        $encoded = json_encode($log->new_values);
        $this->assertStringNotContainsString('sup3r-secret-pw', $encoded);
        $this->assertContains('password', $log->new_values['changed_secret_fields']);
    }

    public function test_user_update_password_change_is_masked_in_audit_log(): void
    {
        $created = $this->asAdmin()->postJson('/api/users', [
            'name' => 'Jelszó Teszt',
            'email' => 'jelszo.teszt@example.com',
            'password' => 'first-password-123',
        ])->json();
        $userId = $created['id'];

        $this->asAdmin()->putJson("/api/users/{$userId}", [
            'name' => 'Jelszó Teszt Módosítva',
            'password' => 'brand-new-secret-456',
        ])->assertOk();

        $log = AuditLog::where('action', 'user.update')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Jelszó Teszt', $log->old_values['name']);
        $this->assertSame('Jelszó Teszt Módosítva', $log->new_values['name']);
        $this->assertArrayNotHasKey('password', $log->old_values);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertContains('password', $log->new_values['changed_secret_fields']);

        $raw = json_encode(['old' => $log->old_values, 'new' => $log->new_values]);
        $this->assertStringNotContainsString('first-password-123', $raw);
        $this->assertStringNotContainsString('brand-new-secret-456', $raw);
    }

    public function test_user_destroy_writes_company_removed_audit_log(): void
    {
        $created = $this->asAdmin()->postJson('/api/users', [
            'name' => 'Törlendő User',
            'email' => 'torlendo.user@example.com',
        ])->json();

        $this->asAdmin()->deleteJson("/api/users/{$created['id']}")->assertNoContent();

        $log = AuditLog::where('action', 'user.company_removed')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->company->id, $log->old_values['company_id']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // UserPermissionOverride — syncOverrides
    // ══════════════════════════════════════════════════════════════════════════

    public function test_permission_override_sync_writes_create_update_delete_audit_logs(): void
    {
        $permission = \App\Models\Permission::create([
            'key' => 'demo.permission',
            'module' => 'demo',
            'description' => 'Demo jog',
            'is_sensitive' => false,
        ]);

        $target = $this->asAdmin()->postJson('/api/users', [
            'name' => 'Override Alany',
            'email' => 'override.alany@example.com',
        ])->json();

        // Grant (create)
        $this->asAdmin()->putJson("/api/users/{$target['id']}/overrides", [
            'overrides' => [$permission->id => 'allow'],
        ])->assertOk();

        $createLog = AuditLog::where('action', 'permission_override.create')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($createLog);
        $this->assertSame('allow', $createLog->new_values['effect']);

        // Change effect (update)
        $this->asAdmin()->putJson("/api/users/{$target['id']}/overrides", [
            'overrides' => [$permission->id => 'deny'],
        ])->assertOk();

        $updateLog = AuditLog::where('action', 'permission_override.update')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($updateLog);
        $this->assertSame('allow', $updateLog->old_values['effect']);
        $this->assertSame('deny', $updateLog->new_values['effect']);

        // Clear (delete)
        $this->asAdmin()->putJson("/api/users/{$target['id']}/overrides", [
            'overrides' => [$permission->id => null],
        ])->assertOk();

        $deleteLog = AuditLog::where('action', 'permission_override.delete')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($deleteLog);
        $this->assertSame('deny', $deleteLog->old_values['effect']);
        $this->assertNull($deleteLog->new_values);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function partnerPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'customer',
            'name' => 'Teszt Partner',
            'billing_postal_code' => '1010',
            'billing_city' => 'Budapest',
            'billing_address_line' => 'Fő utca 1.',
            'billing_country_code' => 'HU',
            'default_currency' => 'HUF',
        ], $overrides);
    }

    private function productPayload(int $vatRateId, array $overrides = []): array
    {
        self::$seq++;

        return array_merge([
            'sku' => 'SKU-'.self::$seq,
            'name' => 'Teszt Termék',
            'unit' => 'db',
            'type' => 'product',
            'vat_rate_id' => $vatRateId,
            'base_price' => 1000,
            'base_currency' => 'HUF',
        ], $overrides);
    }

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::create([
            'name' => 'Company '.self::$seq,
            'tax_number' => '1234567'.self::$seq.'-2-03',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1111',
            'city' => 'Budapest',
            'address_line' => 'Teszt u. 1.',
            'country_code' => 'HU',
            'base_currency' => 'HUF',
        ]);
    }

    private function makeUser(bool $superadmin = false): User
    {
        self::$seq++;

        return User::create([
            'name' => 'User '.self::$seq,
            'email' => 'user'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function enableModule(string $key): void
    {
        $module = Module::firstOrCreate(
            ['key' => $key],
            [
                'name' => ucfirst(str_replace('_', ' ', $key)),
                'description' => "Test module {$key}",
                'version' => '1.0.0',
                'is_core' => false,
                'is_available' => true,
                'sort_order' => 99,
            ]
        );

        $this->company->enabledModules()->attach($module->id, ['enabled' => true]);
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }
}
