<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Partner számlázási cím — billing_country_code mező validációja.
 *
 * Canonikus lista: config/countries.php (ISO 3166-1 alpha-2). A prepareForValidation()
 * nagybetűsíti a bejövő kódot, mielőtt a Rule::in(config('countries')) ellenőrizné.
 */
class PartnerBillingCountryCodeTest extends TestCase
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
    // store
    // ══════════════════════════════════════════════════════════════════════════

    public function test_store_accepts_valid_billing_country_code(): void
    {
        $response = $this->asAdmin($this->company)
            ->postJson('/api/partners', $this->payload(['billing_country_code' => 'DE']));

        $response->assertCreated();
        $response->assertJsonPath('data.billing_country_code', 'DE');
        $this->assertDatabaseHas('partners', ['name' => 'Teszt Partner', 'billing_country_code' => 'DE']);
    }

    public function test_store_rejects_invalid_billing_country_code(): void
    {
        $this->asAdmin($this->company)
            ->postJson('/api/partners', $this->payload(['billing_country_code' => 'ZZ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('billing_country_code');
    }

    public function test_store_normalizes_lowercase_billing_country_code(): void
    {
        $response = $this->asAdmin($this->company)
            ->postJson('/api/partners', $this->payload(['billing_country_code' => 'de']));

        $response->assertCreated();
        $response->assertJsonPath('data.billing_country_code', 'DE');
    }

    public function test_store_requires_billing_country_code(): void
    {
        $payload = $this->payload();
        unset($payload['billing_country_code']);

        $this->asAdmin($this->company)
            ->postJson('/api/partners', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('billing_country_code');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // update
    // ══════════════════════════════════════════════════════════════════════════

    public function test_update_accepts_valid_billing_country_code(): void
    {
        $partner = Partner::create(array_merge($this->payload(), ['company_id' => $this->company->id]));

        $response = $this->asAdmin($this->company)
            ->putJson("/api/partners/{$partner->id}", $this->payload(['billing_country_code' => 'AT']));

        $response->assertOk();
        $response->assertJsonPath('data.billing_country_code', 'AT');
    }

    public function test_update_rejects_invalid_billing_country_code(): void
    {
        $partner = Partner::create(array_merge($this->payload(), ['company_id' => $this->company->id]));

        $this->asAdmin($this->company)
            ->putJson("/api/partners/{$partner->id}", $this->payload(['billing_country_code' => 'ZZ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('billing_country_code');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function payload(array $overrides = []): array
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

    private function asAdmin(Company $company): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
