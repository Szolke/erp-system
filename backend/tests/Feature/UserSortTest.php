<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/users — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * `groups` (a felhasználó RBAC-csoport-neveinek kollekciója) szándékosan
 * NINCS whitelistelve — l. UserController SORTABLE_COLUMNS.
 */
class UserSortTest extends TestCase
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
        $this->makeCompanyUser('Zeta');
        $this->makeCompanyUser('Alfa');

        $names = $this->namesAs([]);

        // A superadmin ("User 1"-hez hasonló név) is a cég tagja, de ábécében
        // az "Alfa"/"Zeta" közé nem esik bele számottevően — csak a két új user sorrendjét nézzük.
        $filtered = array_values(array_filter($names, fn ($n) => in_array($n, ['Alfa', 'Zeta'], true)));

        $this->assertSame(['Alfa', 'Zeta'], $filtered);
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $this->makeCompanyUser('Zeta');
        $this->makeCompanyUser('Alfa');

        $names = $this->namesAs(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'desc']);
        $filtered = array_values(array_filter($names, fn ($n) => in_array($n, ['Alfa', 'Zeta'], true)));

        $this->assertSame(['Alfa', 'Zeta'], $filtered);
    }

    public function test_sorting_by_email_descending(): void
    {
        $this->makeCompanyUser('U1', 'aaa@example.com');
        $this->makeCompanyUser('U2', 'zzz@example.com');

        $emails = array_map(fn ($r) => $r['email'], $this->usersAs(['sort_by' => 'email', 'sort_dir' => 'desc'])['data']);

        $this->assertSame('zzz@example.com', $emails[0]);
        $this->assertContains('aaa@example.com', $emails);
    }

    public function test_sorting_does_not_leak_another_companys_users(): void
    {
        $companyB = $this->makeCompany();
        $userB = $this->makeUser();
        $userB->companies()->attach($companyB->id);

        $this->makeCompanyUser('Zzz Saját');

        $data = $this->usersAs(['sort_by' => 'name', 'sort_dir' => 'desc']);

        $names = array_map(fn ($r) => $r['name'], $data['data']);
        $this->assertContains('Zzz Saját', $names);
        $this->assertNotContains($userB->name, $names);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function namesAs(array $query): array
    {
        return array_map(fn ($row) => $row['name'], $this->usersAs($query)['data']);
    }

    private function usersAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/users?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeCompanyUser(string $name, ?string $email = null): User
    {
        self::$seq++;
        $user = User::create([
            'name' => $name,
            'email' => $email ?? 'u'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => false,
        ]);
        $user->companies()->attach($this->company->id);

        return $user;
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
