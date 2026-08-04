<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\VatRate;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/products — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `vat_rate` a kapcsolt vat_rates.name-re rendez — l. ProductController
 * SORTABLE_COLUMNS + a leftJoin(vat_rates). A többi lista (Partner, Asset,
 * stb.) ugyanezt a mintát követi, ezért itt csak a JOIN-specifikus eseteket
 * és az injektálás-védelmet fedjük le részletesen; a fallback/NULLS LAST
 * viselkedést a DocumentSortTest már bizonyította a ListSort osztályra.
 */
class ProductSortTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User $superadmin;
    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->company = $this->makeCompany();
        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
        $this->vatRate = VatRate::create(['name' => 'ÁFA 27%', 'rate_percent' => 27, 'nav_code' => '27', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_without_sort_params_the_previous_default_order_is_kept(): void
    {
        $this->makeProduct('Zeta');
        $this->makeProduct('Alfa');

        $names = $this->productNames([]);

        $this->assertSame(['Alfa', 'Zeta'], $names, 'Alapértelmezés: név szerint növekvő');
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $this->makeProduct('Zeta');
        $this->makeProduct('Alfa');

        $names = $this->productNames(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'desc']);

        $this->assertSame(['Alfa', 'Zeta'], $names, 'Ismeretlen kulcs: némán vissza az alapértelmezésre');
    }

    public function test_sorting_by_sku_descending(): void
    {
        $this->makeProduct('A', 'SKU-1');
        $this->makeProduct('B', 'SKU-3');
        $this->makeProduct('C', 'SKU-2');

        $skus = array_map(
            fn ($r) => $r['sku'],
            $this->productsAs(['sort_by' => 'sku', 'sort_dir' => 'desc'])['data']
        );

        $this->assertSame(['SKU-3', 'SKU-2', 'SKU-1'], $skus);
    }

    /**
     * A vat_rate rendezés a joinolt vat_rates.name oszlopra fut — ez a
     * lista egyetlen kapcsolt-táblás rendezési szempontja.
     */
    public function test_sorting_by_vat_rate_uses_the_joined_column(): void
    {
        $lowVat = VatRate::create(['name' => 'AAA alacsony', 'rate_percent' => 5, 'nav_code' => '5', 'is_active' => true]);
        $highVat = VatRate::create(['name' => 'ZZZ magas', 'rate_percent' => 27, 'nav_code' => '27', 'is_active' => true]);

        $this->makeProduct('Termék B', 'SKU-B', $highVat->id);
        $this->makeProduct('Termék A', 'SKU-A', $lowVat->id);

        $names = array_map(
            fn ($r) => $r['vat_rate']['name'],
            $this->productsAs(['sort_by' => 'vat_rate', 'sort_dir' => 'asc'])['data']
        );

        $this->assertSame(['AAA alacsony', 'ZZZ magas'], $names);
    }

    public function test_sorting_does_not_leak_another_companys_products(): void
    {
        $companyB = $this->makeCompany();
        app(CurrentCompany::class)->set($companyB->id);
        Product::create(['company_id' => $companyB->id, 'sku' => 'OTHER', 'name' => 'Aaa Elsőnek Tűnne', 'unit' => 'db', 'type' => 'product', 'vat_rate_id' => $this->vatRate->id, 'base_price' => 1, 'base_currency' => 'HUF']);
        app(CurrentCompany::class)->clear();

        $this->makeProduct('Zzz Saját');

        $data = $this->productsAs(['sort_by' => 'name', 'sort_dir' => 'asc']);

        $this->assertSame(1, $data['meta']['total'], 'Csak a saját cég termékei');
        $this->assertSame('Zzz Saját', $data['data'][0]['name']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function productNames(array $query): array
    {
        return array_map(fn ($row) => $row['name'], $this->productsAs($query)['data']);
    }

    private function productsAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/products?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeProduct(string $name, ?string $sku = null, ?int $vatRateId = null): Product
    {
        app(CurrentCompany::class)->set($this->company->id);

        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => $sku ?? 'SKU-'.$name,
            'name' => $name,
            'unit' => 'db',
            'type' => 'product',
            'vat_rate_id' => $vatRateId ?? $this->vatRate->id,
            'base_price' => 100,
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->clear();

        return $product;
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
