<?php

namespace Tests\Feature;

use App\Enums\NavEnvironment;
use App\Models\Company;
use App\Models\CompanyEnyugtaCredential;
use App\Models\CompanyNavCredential;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HTTP-szintű tesztek a GET/PUT /api/settings/enyugta és a
 * POST /api/settings/enyugta/copy-from-nav végpontokra (1. fázis: csak
 * beállítás-tárolás, nincs beküldés).
 *
 * Fedi:
 *   - titkos mezők SOHA nem jelennek meg a GET válaszban (csak has_* flag)
 *   - PUT create/update (titkos mező üresen hagyva update-nél = megtartja a régit)
 *   - copy-from-nav: helyesen másol, és nem másol, ha nincs forrás
 *   - multi-tenant izoláció: A cég nem éri el B cég credentialját
 */
class EnyugtaSettingsControllerTest extends TestCase
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
    // GET — secret masking
    // ══════════════════════════════════════════════════════════════════════

    public function test_show_returns_default_empty_state_when_no_credential_exists(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.view']);

        $response = $this->actingAs($user)->getJson('/api/settings/enyugta');

        $response->assertOk();
        $response->assertJsonPath('data.has_login', false);
        $response->assertJsonPath('data.has_password', false);
        $response->assertJsonPath('data.has_signing_key', false);
        $response->assertJsonPath('data.has_exchange_key', false);
        $response->assertJsonPath('data.mode', null);
    }

    public function test_show_never_leaks_secret_values(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.view']);

        CompanyEnyugtaCredential::create([
            'company_id'   => $company->id,
            'login'        => 'very-secret-login',
            'password'     => 'very-secret-password',
            'signing_key'  => 'very-secret-signing-key',
            'exchange_key' => 'very-secret-exchange-key',
            'tax_number'   => '12345678',
            'mode'         => 'mock',
        ]);

        $response = $this->actingAs($user)->getJson('/api/settings/enyugta');

        $response->assertOk();
        $raw = $response->getContent();

        foreach (['very-secret-login', 'very-secret-password', 'very-secret-signing-key', 'very-secret-exchange-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "A GET válasz NEM tartalmazhatja a(z) '{$secret}' titkos értéket semmilyen formában.");
        }

        $response->assertJsonPath('data.has_login', true);
        $response->assertJsonPath('data.has_password', true);
        $response->assertJsonPath('data.has_signing_key', true);
        $response->assertJsonPath('data.has_exchange_key', true);
        $response->assertJsonPath('data.tax_number', '12345678'); // nem titkos, ez megjelenhet
    }

    public function test_secret_fields_stored_encrypted_in_database(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.manage']);
        app(CurrentCompany::class)->set($company->id);

        $this->actingAs($user)->putJson('/api/settings/enyugta', [
            'login' => 'plain-login',
            'password' => 'plain-password',
            'signing_key' => 'plain-signing-key',
            'exchange_key' => 'plain-exchange-key',
            'tax_number' => '12345678',
            'mode' => 'mock',
            'send_empty_reports' => false,
        ])->assertOk();

        $rawRow = DB::table('company_enyugta_credentials')->where('company_id', $company->id)->first();

        $this->assertStringNotContainsString('plain-login', $rawRow->login);
        $this->assertStringNotContainsString('plain-password', $rawRow->password);
        $this->assertStringNotContainsString('plain-signing-key', $rawRow->signing_key);
        $this->assertStringNotContainsString('plain-exchange-key', $rawRow->exchange_key);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUT — create / update
    // ══════════════════════════════════════════════════════════════════════

    public function test_put_requires_enyugta_manage_permission(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.view']); // csak view, nincs manage

        $response = $this->actingAs($user)->putJson('/api/settings/enyugta', [
            'login' => 'a', 'password' => 'b', 'signing_key' => 'c', 'exchange_key' => 'd',
            'tax_number' => '12345678', 'mode' => 'mock', 'send_empty_reports' => false,
        ]);

        $response->assertForbidden();
    }

    public function test_put_creates_new_credential_requiring_all_secret_fields(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.manage']);

        $response = $this->actingAs($user)->putJson('/api/settings/enyugta', [
            'login' => 'login1', 'password' => 'pass1', 'signing_key' => 'sign1', 'exchange_key' => 'exch1',
            'tax_number' => '12345678', 'mode' => 'test', 'base_url_override' => 'https://example.test',
            'send_empty_reports' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.has_login', true);
        $response->assertJsonPath('data.mode', 'test');
        $response->assertJsonPath('data.send_empty_reports', true);

        $this->assertDatabaseCount('company_enyugta_credentials', 1);
    }

    public function test_put_new_credential_fails_when_secret_field_missing(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.manage']);

        $response = $this->actingAs($user)->putJson('/api/settings/enyugta', [
            'login' => 'login1', // password/signing_key/exchange_key hiányzik
            'tax_number' => '12345678', 'mode' => 'mock', 'send_empty_reports' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_put_update_with_empty_secret_keeps_existing_value(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.manage']);
        app(CurrentCompany::class)->set($company->id);

        CompanyEnyugtaCredential::create([
            'company_id' => $company->id, 'login' => 'original-login', 'password' => 'original-password',
            'signing_key' => 'original-signing', 'exchange_key' => 'original-exchange',
            'tax_number' => '12345678', 'mode' => 'mock',
        ]);

        // Update: csak a mode-ot változtatjuk, a titkos mezőket üresen hagyjuk.
        $response = $this->actingAs($user)->putJson('/api/settings/enyugta', [
            'tax_number' => '12345678', 'mode' => 'test', 'send_empty_reports' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.mode', 'test');
        $response->assertJsonPath('data.has_login', true); // a régi érték megmaradt

        $fresh = CompanyEnyugtaCredential::where('company_id', $company->id)->first();
        $this->assertSame('original-login', $fresh->login);
    }

    // ══════════════════════════════════════════════════════════════════════
    // copy-from-nav
    // ══════════════════════════════════════════════════════════════════════

    public function test_copy_from_nav_copies_matching_environment(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.manage']);
        app(CurrentCompany::class)->set($company->id);

        CompanyNavCredential::create([
            'company_id' => $company->id, 'environment' => NavEnvironment::Test,
            'nav_tax_number' => '87654321', 'nav_login' => 'nav-login', 'nav_password' => 'nav-password',
            'nav_signing_key' => 'nav-signing', 'nav_exchange_key' => 'nav-exchange', 'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/settings/enyugta/copy-from-nav', [
            'environment' => 'test',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.has_login', true);
        $response->assertJsonPath('data.has_password', true);
        $response->assertJsonPath('data.tax_number', '87654321');

        $fresh = CompanyEnyugtaCredential::where('company_id', $company->id)->first();
        $this->assertSame('nav-login', $fresh->login);
        $this->assertSame('87654321', $fresh->tax_number);
    }

    public function test_copy_from_nav_fails_cleanly_when_no_source_credential(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.manage']);
        // nincs company_nav_credentials sor

        $response = $this->actingAs($user)->postJson('/api/settings/enyugta/copy-from-nav', [
            'environment' => 'test',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('company_enyugta_credentials', 0);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Multi-tenant izoláció
    // ══════════════════════════════════════════════════════════════════════

    public function test_company_a_cannot_see_company_b_credential(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithUser(['enyugta.view']);
        [$companyB, $userB] = $this->makeCompanyWithUser(['enyugta.view']);

        app(CurrentCompany::class)->set($companyB->id);
        CompanyEnyugtaCredential::create([
            'company_id' => $companyB->id, 'login' => 'b-login', 'password' => 'b-password',
            'signing_key' => 'b-signing', 'exchange_key' => 'b-exchange', 'tax_number' => '99999999', 'mode' => 'mock',
        ]);

        $response = $this->actingAs($userA)->getJson('/api/settings/enyugta');

        $response->assertOk();
        // A cégnek nincs saját sora → üres alapállapotot kell látnia, NEM a B cég adatait.
        $response->assertJsonPath('data.has_login', false);
        $response->assertJsonPath('data.tax_number', null);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    /** @return array{0: Company, 1: User} */
    private function makeCompanyWithUser(array $permissionKeys): array
    {
        self::$seq++;

        $company = Company::withoutGlobalScope('company')->create([
            'name'                => 'eNyugta Company '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-05',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $module = Module::firstOrCreate(
            ['key' => 'enyugta'],
            ['name' => 'eNyugta', 'description' => 'test', 'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10]
        );
        $company->enabledModules()->attach($module->id, ['enabled' => true]);

        $user = User::create([
            'name' => 'User '.self::$seq,
            'email' => 'enyugta.settings.user'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => false,
        ]);
        $company->users()->attach($user->id);
        $user->update(['default_company_id' => $company->id]);

        $group = Group::create(['company_id' => $company->id, 'name' => 'eNyugta csoport '.self::$seq]);
        $group->users()->attach($user->id);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['module' => 'enyugta']);
            $group->permissions()->attach($permission->id);
        }

        app(CurrentCompany::class)->set($company->id);

        return [$company, $user];
    }
}
