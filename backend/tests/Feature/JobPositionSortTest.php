<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobPosition;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/job-positions — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `scope` az AssetType mintáját követi: (company_id IS NULL) —
 * l. JobPositionController SORTABLE_COLUMNS.
 */
class JobPositionSortTest extends TestCase
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

    public function test_without_sort_params_the_previous_sort_order_default_is_kept(): void
    {
        $this->makePosition('Zeta', sortOrder: 2);
        $this->makePosition('Alfa', sortOrder: 1);

        $this->assertSame(['Alfa', 'Zeta'], $this->namesAs([]));
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $this->makePosition('Zeta', sortOrder: 2);
        $this->makePosition('Alfa', sortOrder: 1);

        $names = $this->namesAs(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'desc']);

        $this->assertSame(['Alfa', 'Zeta'], $names);
    }

    public function test_sorting_by_name_descending(): void
    {
        $this->makePosition('Alfa');
        $this->makePosition('Zeta');

        $names = array_map(
            fn ($r) => $r['name'],
            $this->positionsAs(['sort_by' => 'name', 'sort_dir' => 'desc'])['data']
        );

        $this->assertSame(['Zeta', 'Alfa'], $names);
    }

    public function test_sorting_by_scope_groups_global_and_company_owned_positions_separately(): void
    {
        JobPosition::create(['company_id' => null, 'name' => 'Globális', 'active' => true, 'sort_order' => 0]);
        $this->makePosition('Céges');

        $asc = $this->positionsAs(['sort_by' => 'scope', 'sort_dir' => 'asc'])['data'];
        $desc = $this->positionsAs(['sort_by' => 'scope', 'sort_dir' => 'desc'])['data'];

        $this->assertFalse($asc[0]['is_global']);
        $this->assertTrue($desc[0]['is_global']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function namesAs(array $query): array
    {
        return array_map(fn ($row) => $row['name'], $this->positionsAs($query)['data']);
    }

    private function positionsAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/job-positions?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makePosition(string $name, int $sortOrder = 0): JobPosition
    {
        app(CurrentCompany::class)->set($this->company->id);
        $position = JobPosition::create([
            'company_id' => $this->company->id, 'name' => $name, 'active' => true, 'sort_order' => $sortOrder,
        ]);
        app(CurrentCompany::class)->clear();

        return $position;
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
