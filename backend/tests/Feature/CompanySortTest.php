<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/companies — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `users_count` a withCount('users') SELECT-aliasára rendez —
 * l. CompanyController SORTABLE_COLUMNS. Csak szuperadmin érheti el.
 */
class CompanySortTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->superadmin = $this->makeUser(superadmin: true);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_without_sort_params_the_previous_default_order_is_kept(): void
    {
        $this->makeCompany('Zeta Kft.');
        $this->makeCompany('Alfa Kft.');

        $this->assertSame(['Alfa Kft.', 'Zeta Kft.'], $this->namesAs([]));
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $this->makeCompany('Zeta Kft.');
        $this->makeCompany('Alfa Kft.');

        $names = $this->namesAs(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'desc']);

        $this->assertSame(['Alfa Kft.', 'Zeta Kft.'], $names);
    }

    /** `users_count` a withCount('users') select-aliasára rendez. */
    public function test_sorting_by_users_count_uses_the_select_alias(): void
    {
        $small = $this->makeCompany('Kicsi Kft.');
        $big = $this->makeCompany('Nagy Kft.');

        $big->users()->attach([$this->superadmin->id]);
        $extra = $this->makeUser();
        $big->users()->attach([$extra->id]);
        $small->users()->attach([$this->superadmin->id]);

        $desc = $this->companiesAs(['sort_by' => 'users_count', 'sort_dir' => 'desc'])['data'];

        $this->assertSame('Nagy Kft.', $desc[0]['name']);
        $this->assertSame(2, $desc[0]['users_count']);
    }

    public function test_sorting_by_city_ascending(): void
    {
        $this->makeCompany('B Kft.', 'Zalaegerszeg');
        $this->makeCompany('A Kft.', 'Budapest');

        $cities = array_map(
            fn ($r) => $r['city'],
            $this->companiesAs(['sort_by' => 'city', 'sort_dir' => 'asc'])['data']
        );

        $this->assertSame(['Budapest', 'Zalaegerszeg'], $cities);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function namesAs(array $query): array
    {
        return array_map(fn ($row) => $row['name'], $this->companiesAs($query)['data']);
    }

    private function companiesAs(array $query): array
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->getJson('/api/companies?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeCompany(string $name, string $city = 'Budapest'): Company
    {
        self::$seq++;

        return Company::create([
            'name'                => $name,
            'tax_number'          => '1234567'.self::$seq.'-2-03',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => $city,
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
}
