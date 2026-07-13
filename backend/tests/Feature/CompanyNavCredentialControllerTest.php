<?php

namespace Tests\Feature;

use App\Enums\NavEnvironment;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyNavCredential;
use App\Models\Module;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-szintű tesztek a CompanyNavCredentialController háromszoros védelmére:
 *
 *   PATCH /api/company/nav/active-environment
 *     → 422, ha a CÉL environmenthez nincs is_active=true credential
 *
 *   DELETE /api/company/nav/{environment}
 *     → 422, ha a törlendő environment az aktív (company.nav_environment)
 *
 *   PUT /api/company/nav/{environment}
 *     → 422, ha az aktív environment hitelesítőjét is_active=false-ra próbálják állítani
 *
 * Mindhárom guard a SendInvoiceToNavJob where('is_active', true) feltételét tükrözi:
 * ha ezek az állapotok beállnak, a számlák némán nem mennének ki a NAV-hoz.
 */
class CompanyNavCredentialControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User    $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        self::$seq++;

        $this->company = Company::create([
            'name'                => 'NAV Cég '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. '.self::$seq.'.',
            'base_currency'       => 'HUF',
            'nav_environment'     => NavEnvironment::Test,
        ]);

        $this->admin = User::create([
            'name'          => 'Admin '.self::$seq,
            'email'         => 'nav.admin.'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => true,
        ]);

        $this->company->users()->attach($this->admin->id);

        // NAV modul engedélyezése — a module:nav middleware 404-et ad nélküle.
        $navModule = Module::create([
            'key'          => 'nav',
            'name'         => 'NAV Online Számla',
            'description'  => 'Test nav module',
            'version'      => '1.0.0',
            'is_core'      => false,
            'is_available' => true,
            'sort_order'   => 10,
        ]);
        $this->company->enabledModules()->attach($navModule->id, ['enabled' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ─── PATCH /active-environment ────────────────────────────────────────────

    public function test_patch_active_environment_returns_422_when_target_has_no_active_credential(): void
    {
        // company.nav_environment = Test, de nincs is_active=true credential → 422
        $response = $this->asAdmin()->patchJson('/api/company/nav/active-environment', [
            'environment' => 'production',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('production', $response->json('message'));
    }

    public function test_patch_active_environment_succeeds_when_target_has_active_credential(): void
    {
        $this->makeCredential(NavEnvironment::Production, isActive: true);

        $response = $this->asAdmin()->patchJson('/api/company/nav/active-environment', [
            'environment' => 'production',
        ]);

        $response->assertOk();
        $response->assertJson(['active_environment' => 'production']);
    }

    public function test_patch_active_environment_returns_422_when_credential_exists_but_inactive(): void
    {
        // Credential létezik, de is_active=false → ugyanúgy 422 (tükrözi a job viselkedését)
        $this->makeCredential(NavEnvironment::Production, isActive: false);

        $response = $this->asAdmin()->patchJson('/api/company/nav/active-environment', [
            'environment' => 'production',
        ]);

        $response->assertStatus(422);
    }

    // ─── DELETE /{environment} ────────────────────────────────────────────────

    public function test_delete_returns_422_when_deleting_active_environment_credential(): void
    {
        // company.nav_environment = Test → Test nem törölhető
        $this->makeCredential(NavEnvironment::Test, isActive: true);

        $response = $this->asAdmin()->deleteJson('/api/company/nav/test');

        $response->assertStatus(422);
        $this->assertStringContainsString('test', $response->json('message'));
    }

    public function test_delete_succeeds_when_deleting_inactive_environment_credential(): void
    {
        // company.nav_environment = Test → Production törölhető
        $this->makeCredential(NavEnvironment::Production, isActive: true);

        $response = $this->asAdmin()->deleteJson('/api/company/nav/production');

        $response->assertOk();
        $this->assertDatabaseMissing('company_nav_credentials', [
            'company_id'  => $this->company->id,
            'environment' => 'production',
        ]);
    }

    // ─── PUT /{environment} (is_active kikapcsolás) ───────────────────────────

    public function test_upsert_returns_422_when_deactivating_active_environment_credential(): void
    {
        // company.nav_environment = Test → a Test credential is_active=false-ra nem állítható
        $cred = $this->makeCredential(NavEnvironment::Test, isActive: true);

        $response = $this->asAdmin()->putJson('/api/company/nav/test', [
            'nav_tax_number' => $cred->nav_tax_number,
            'is_active'      => false,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('test', $response->json('message'));
    }

    public function test_upsert_allows_deactivating_non_active_environment_credential(): void
    {
        // company.nav_environment = Test → Production kikapcsolható
        $cred = $this->makeCredential(NavEnvironment::Production, isActive: true);

        $response = $this->asAdmin()->putJson('/api/company/nav/production', [
            'nav_tax_number' => $cred->nav_tax_number,
            'is_active'      => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('company_nav_credentials', [
            'company_id'  => $this->company->id,
            'environment' => 'production',
            'is_active'   => false,
        ]);
    }

    public function test_upsert_allows_updating_other_fields_on_active_environment_credential(): void
    {
        // Az aktív environment credentialján is_active=true marad → az adószám módosítható
        $cred = $this->makeCredential(NavEnvironment::Test, isActive: true);

        $response = $this->asAdmin()->putJson('/api/company/nav/test', [
            'nav_tax_number' => '99999999-1-41',
            'is_active'      => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('company_nav_credentials', [
            'company_id'    => $this->company->id,
            'environment'   => 'test',
            'nav_tax_number'=> '99999999-1-41',
            'is_active'     => true,
        ]);
    }

    // ─── PUT /api/company nem írhatja felül a nav_environment-t ──────────────
    //
    // A PATCH /active-environment a védett kapu; a PUT /api/company nem
    // validálja a mezőt → $request->validated() kizárja → update() figyelmen
    // kívül hagyja. Ez a negyedik ajtó a háromszoros védelmen.

    public function test_company_update_ignores_nav_environment_field(): void
    {
        // Kiindulás: Test (setUp-ban beállított nav_environment)
        $this->assertSame('test', $this->company->fresh()->nav_environment->value);

        $response = $this->asAdmin()->putJson('/api/company', [
            'name'                => $this->company->name,
            'tax_number'          => $this->company->tax_number,
            'registration_number' => $this->company->registration_number,
            'postal_code'         => $this->company->postal_code,
            'city'                => $this->company->city,
            'address_line'        => $this->company->address_line,
            'base_currency'       => $this->company->base_currency,
            'country_code'        => 'HU',
            'nav_environment'     => 'production', // ← ezt ignorálni kell
        ]);

        $response->assertOk();

        // nav_environment nem változhatott — csak PATCH /active-environment úton módosítható
        $this->assertSame('test', $this->company->fresh()->nav_environment->value);
    }

    // ─── Audit log ───────────────────────────────────────────────────────────

    public function test_upsert_creates_audit_log_without_sensitive_values(): void
    {
        $response = $this->asAdmin()->putJson('/api/company/nav/test', [
            'nav_tax_number'   => '12345678-1-41',
            'nav_login'        => 'audit-secret-login',
            'nav_password'     => 'audit-secret-pass',
            'nav_signing_key'  => 'audit-secret-sign',
            'nav_exchange_key' => 'audit-secret-exch',
            'is_active'        => true,
        ]);

        $response->assertOk();

        $log = AuditLog::where('action', 'nav_credential.created')
            ->where('company_id', $this->company->id)
            ->first();
        $this->assertNotNull($log, 'nav_credential.created audit bejegyzés hiányzik');

        // Titkos értékek nem kerülhetnek az audit-logba (csak a mezőnevük)
        $encoded = json_encode($log->new_values);
        $this->assertStringNotContainsString('audit-secret-login', $encoded);
        $this->assertStringNotContainsString('audit-secret-pass',  $encoded);
        $this->assertStringNotContainsString('audit-secret-sign',  $encoded);
        $this->assertStringNotContainsString('audit-secret-exch',  $encoded);

        // Nem titkos adatok szerepelnek
        $this->assertSame('12345678-1-41', $log->new_values['nav_tax_number']);
        $this->assertContains('nav_login',        $log->new_values['changed_secret_fields']);
        $this->assertContains('nav_signing_key',  $log->new_values['changed_secret_fields']);
    }

    public function test_delete_creates_audit_log(): void
    {
        $this->makeCredential(NavEnvironment::Production, isActive: true);

        $this->asAdmin()->deleteJson('/api/company/nav/production')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'nav_credential.deleted',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_set_active_environment_creates_audit_log_with_old_and_new(): void
    {
        // company.nav_environment = Test (setUp), váltunk production-re
        $this->makeCredential(NavEnvironment::Production, isActive: true);

        $this->asAdmin()->patchJson('/api/company/nav/active-environment', [
            'environment' => 'production',
        ])->assertOk();

        $log = AuditLog::where('action', 'nav_environment.changed')
            ->where('company_id', $this->company->id)
            ->first();
        $this->assertNotNull($log, 'nav_environment.changed audit bejegyzés hiányzik');
        $this->assertSame(['nav_environment' => 'test'],       $log->old_values);
        $this->assertSame(['nav_environment' => 'production'], $log->new_values);
    }

    public function test_audit_log_does_not_contain_sensitive_values_in_db(): void
    {
        // Szivárgás-teszt: az audit_logs tábla raw JSON-jában sem szerepelhet titkos érték.
        $this->asAdmin()->putJson('/api/company/nav/test', [
            'nav_tax_number'   => '99999999-1-42',
            'nav_login'        => 'LEAK-TEST-LOGIN-XYZ',
            'nav_password'     => 'LEAK-TEST-PASS-XYZ',
            'nav_signing_key'  => 'LEAK-TEST-SIGN-XYZ',
            'nav_exchange_key' => 'LEAK-TEST-EXCH-XYZ',
            'is_active'        => true,
        ])->assertOk();

        // Az összes audit-log bejegyzésben egyetlen titkos érték sem bukkanhat fel
        AuditLog::where('company_id', $this->company->id)->each(function (AuditLog $row) {
            $raw = json_encode(['old' => $row->old_values, 'new' => $row->new_values]);
            $this->assertStringNotContainsString('LEAK-TEST-LOGIN-XYZ', $raw);
            $this->assertStringNotContainsString('LEAK-TEST-PASS-XYZ',  $raw);
            $this->assertStringNotContainsString('LEAK-TEST-SIGN-XYZ',  $raw);
            $this->assertStringNotContainsString('LEAK-TEST-EXCH-XYZ',  $raw);
        });
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }

    private function makeCredential(NavEnvironment $env, bool $isActive = true): CompanyNavCredential
    {
        return CompanyNavCredential::create([
            'company_id'       => $this->company->id,
            'environment'      => $env,
            'nav_tax_number'   => $this->company->tax_number,
            'nav_login'        => 'test-login',
            'nav_password'     => 'test-password',
            'nav_signing_key'  => 'test-signing-key',
            'nav_exchange_key' => 'test-exchange-key',
            'is_active'        => $isActive,
        ]);
    }
}
