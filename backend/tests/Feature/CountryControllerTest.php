<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Globális "countries" törzs — 1. unit (backend).
 *
 * A `countries` tábla a config('countries') (ISO 3166-1 alpha-2) PROJEKCIÓJA + a
 * superadmin által kapcsolható `enabled` állapot. A config a validáció egyetlen
 * igazságforrása; a `erp:sync-countries` sosem írja felül a meglévő sorok enabled
 * állapotát, sosem töröl.
 */
class CountryControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User $superadmin;
    private User $normalUser;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->company = $this->makeCompany();

        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);

        $this->normalUser = $this->makeUser();
        $this->normalUser->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // erp:sync-countries idempotencia
    // ══════════════════════════════════════════════════════════════════════════

    public function test_sync_command_inserts_all_config_codes(): void
    {
        $this->artisan('erp:sync-countries')->assertExitCode(0);

        $this->assertSame(count(config('countries')), Country::count());
        $this->assertSame(0, Country::where('enabled', false)->count());
    }

    public function test_sync_command_is_idempotent_and_preserves_disabled_state(): void
    {
        $this->artisan('erp:sync-countries')->assertExitCode(0);
        $countAfterFirstRun = Country::count();

        Country::where('code', 'US')->update(['enabled' => false]);

        $this->artisan('erp:sync-countries')->assertExitCode(0);

        $this->assertSame($countAfterFirstRun, Country::count());
        $this->assertFalse(Country::where('code', 'US')->first()->enabled);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /api/countries — selector, csak enabled kódok
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_only_enabled_codes(): void
    {
        $this->artisan('erp:sync-countries');
        Country::where('code', 'US')->update(['enabled' => false]);
        Country::where('code', 'GB')->update(['enabled' => false]);

        $response = $this->asUser($this->normalUser, $this->company)->getJson('/api/countries');

        $response->assertOk();
        $codes = $response->json('data');
        $this->assertContains('HU', $codes);
        $this->assertContains('DE', $codes);
        $this->assertNotContains('US', $codes);
        $this->assertNotContains('GB', $codes);
        $this->assertCount(count(config('countries')) - 2, $codes);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /api/admin/countries — superadmin-only
    // ══════════════════════════════════════════════════════════════════════════

    public function test_admin_index_returns_all_countries_with_enabled_state_for_superadmin(): void
    {
        $this->artisan('erp:sync-countries');
        Country::where('code', 'US')->update(['enabled' => false]);

        $response = $this->asUser($this->superadmin, $this->company)->getJson('/api/admin/countries');

        $response->assertOk();
        $this->assertCount(count(config('countries')), $response->json('data'));
        $us = collect($response->json('data'))->firstWhere('code', 'US');
        $this->assertFalse($us['enabled']);
    }

    public function test_admin_index_forbidden_for_non_superadmin(): void
    {
        $this->artisan('erp:sync-countries');

        $this->asUser($this->normalUser, $this->company)
            ->getJson('/api/admin/countries')
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PUT /api/admin/countries — engedélyezett halmaz cseréje
    // ══════════════════════════════════════════════════════════════════════════

    public function test_admin_update_replaces_enabled_set_for_superadmin(): void
    {
        $this->artisan('erp:sync-countries');

        $response = $this->asUser($this->superadmin, $this->company)
            ->putJson('/api/admin/countries', ['codes' => ['HU', 'AT', 'DE']]);

        $response->assertOk();
        $this->assertTrue(Country::where('code', 'HU')->first()->enabled);
        $this->assertTrue(Country::where('code', 'AT')->first()->enabled);
        $this->assertTrue(Country::where('code', 'DE')->first()->enabled);
        $this->assertFalse(Country::where('code', 'US')->first()->enabled);
        $this->assertSame(3, Country::where('enabled', true)->count());
    }

    public function test_admin_update_rejects_invalid_code(): void
    {
        $this->artisan('erp:sync-countries');

        $this->asUser($this->superadmin, $this->company)
            ->putJson('/api/admin/countries', ['codes' => ['HU', 'ZZ']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codes.1');
    }

    public function test_admin_update_forbidden_for_non_superadmin(): void
    {
        $this->artisan('erp:sync-countries');

        $this->asUser($this->normalUser, $this->company)
            ->putJson('/api/admin/countries', ['codes' => ['HU']])
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::create([
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

    private function asUser(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
