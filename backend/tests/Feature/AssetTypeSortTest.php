<?php

namespace Tests\Feature;

use App\Models\AssetType;
use App\Models\Company;
use App\Models\Module;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/asset-types — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `scope` egy KÓDBAN rögzített logikai kifejezés ((company_id IS NULL)),
 * nem kérésből jövő oszlopnév — l. AssetTypeController SORTABLE_COLUMNS.
 */
class AssetTypeSortTest extends TestCase
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

        $module = Module::create([
            'key' => 'assets', 'name' => 'Assets', 'description' => 'Test module assets',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10,
        ]);
        $this->company->enabledModules()->attach($module->id, ['enabled' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_without_sort_params_the_previous_default_order_is_kept(): void
    {
        AssetType::create(['company_id' => null, 'code' => 'ZZZ', 'name' => 'Z típus']);
        AssetType::create(['company_id' => null, 'code' => 'AAA', 'name' => 'A típus']);

        $codes = $this->codesAs([]);

        $this->assertSame(['AAA', 'ZZZ'], $codes, 'Alapértelmezés: code szerint növekvő');
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        AssetType::create(['company_id' => null, 'code' => 'ZZZ', 'name' => 'Z típus']);
        AssetType::create(['company_id' => null, 'code' => 'AAA', 'name' => 'A típus']);

        $codes = $this->codesAs(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'desc']);

        $this->assertSame(['AAA', 'ZZZ'], $codes);
    }

    public function test_sorting_by_name_descending(): void
    {
        AssetType::create(['company_id' => null, 'code' => 'C1', 'name' => 'Alfa']);
        AssetType::create(['company_id' => null, 'code' => 'C2', 'name' => 'Zeta']);

        $names = array_map(fn ($r) => $r['name'], $this->assetTypesAs(['sort_by' => 'name', 'sort_dir' => 'desc'])['data']);

        $this->assertSame(['Zeta', 'Alfa'], $names);
    }

    /** `scope` — a globális (company_id IS NULL) tétel a másik oldalra kerül a céges mellett. */
    public function test_sorting_by_scope_groups_global_and_company_owned_types_separately(): void
    {
        AssetType::create(['company_id' => null, 'code' => 'GLOB', 'name' => 'Globális']);
        AssetType::create(['company_id' => $this->company->id, 'code' => 'OWN', 'name' => 'Céges']);

        $asc = $this->assetTypesAs(['sort_by' => 'scope', 'sort_dir' => 'asc'])['data'];
        $desc = $this->assetTypesAs(['sort_by' => 'scope', 'sort_dir' => 'desc'])['data'];

        $this->assertFalse($asc[0]['is_global'], 'ASC: a céges (false) tétel elöl');
        $this->assertTrue($desc[0]['is_global'], 'DESC: a globális (true) tétel elöl');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function codesAs(array $query): array
    {
        return array_map(fn ($row) => $row['code'], $this->assetTypesAs($query)['data']);
    }

    private function assetTypesAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/asset-types?'.http_build_query($query))
            ->assertOk()
            ->json();
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
