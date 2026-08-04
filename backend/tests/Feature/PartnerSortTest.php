<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/partners — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `city` a `billing_city` oszlopra rendez (l. PartnerController
 * SORTABLE_COLUMNS) — a partnerlistán a szállítási cím nem jelenik meg.
 * A ListSort osztály injektálás-védelmét/fallback-jét a DocumentSortTest
 * már bizonyította; itt csak a Partner-specifikus oszlop-leképezést és a
 * company-scope megőrzését teszteljük.
 */
class PartnerSortTest extends TestCase
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

    public function test_without_sort_params_the_previous_default_order_is_kept(): void
    {
        $this->makePartner('Zeta');
        $this->makePartner('Alfa');

        $this->assertSame(['Alfa', 'Zeta'], $this->partnerNames([]));
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $this->makePartner('Zeta');
        $this->makePartner('Alfa');

        $names = $this->partnerNames(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'desc']);

        $this->assertSame(['Alfa', 'Zeta'], $names);
    }

    /** A frontend-kulcs `city`, a DB-oszlop `billing_city` — a leképezés helyességét teszteli. */
    public function test_sorting_by_city_uses_the_billing_city_column(): void
    {
        $this->makePartner('Partner B', 'Zalaegerszeg');
        $this->makePartner('Partner A', 'Budapest');

        $cities = array_map(
            fn ($r) => $r['billing_city'],
            $this->partnersAs(['sort_by' => 'city', 'sort_dir' => 'asc'])['data']
        );

        $this->assertSame(['Budapest', 'Zalaegerszeg'], $cities);
    }

    public function test_sorting_by_tax_number_descending(): void
    {
        $this->makePartner('P1', 'Budapest', '10000000-1-11');
        $this->makePartner('P2', 'Budapest', '30000000-1-11');
        $this->makePartner('P3', 'Budapest', '20000000-1-11');

        $taxNumbers = array_map(
            fn ($r) => $r['tax_number'],
            $this->partnersAs(['sort_by' => 'tax_number', 'sort_dir' => 'desc'])['data']
        );

        $this->assertSame(['30000000-1-11', '20000000-1-11', '10000000-1-11'], $taxNumbers);
    }

    public function test_sorting_does_not_leak_another_companys_partners(): void
    {
        $companyB = $this->makeCompany();
        app(CurrentCompany::class)->set($companyB->id);
        Partner::create([
            'company_id' => $companyB->id, 'type' => 'customer', 'name' => 'Aaa Elsőnek Tűnne',
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);
        app(CurrentCompany::class)->clear();

        $this->makePartner('Zzz Saját');

        $data = $this->partnersAs(['sort_by' => 'name', 'sort_dir' => 'asc']);

        $this->assertSame(1, $data['meta']['total']);
        $this->assertSame('Zzz Saját', $data['data'][0]['name']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function partnerNames(array $query): array
    {
        return array_map(fn ($row) => $row['name'], $this->partnersAs($query)['data']);
    }

    private function partnersAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/partners?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makePartner(string $name, string $city = 'Budapest', string $taxNumber = '11111111-1-11'): Partner
    {
        app(CurrentCompany::class)->set($this->company->id);

        $partner = Partner::create([
            'company_id' => $this->company->id,
            'type' => 'customer',
            'name' => $name,
            'tax_number' => $taxNumber,
            'billing_postal_code' => '1000',
            'billing_city' => $city,
            'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->clear();

        return $partner;
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
