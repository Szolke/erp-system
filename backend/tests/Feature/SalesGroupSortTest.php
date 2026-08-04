<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Module;
use App\Models\SalesGroup;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/sales-groups — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `display_name` a SalesGroupResource::toArray()-ben számolt értéket
 * tükrözi (cég `group_prefix` + `_` + név) — l. SalesGroupController
 * SORTABLE_COLUMNS + a leftJoin(companies).
 */
class SalesGroupSortTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->company = $this->makeCompany('PFX');
        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);

        $module = Module::create([
            'key' => 'sales_group', 'name' => 'Sales groups', 'description' => 'Test module sales_group',
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
        $this->makeGroup('Zeta');
        $this->makeGroup('Alfa');

        $this->assertSame(['Alfa', 'Zeta'], $this->groupNames([]));
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $this->makeGroup('Zeta');
        $this->makeGroup('Alfa');

        $names = $this->groupNames(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'desc']);

        $this->assertSame(['Alfa', 'Zeta'], $names);
    }

    /** display_name = "PFX_<name>" — a joinolt companies.group_prefix + `_` + a saját név. */
    public function test_sorting_by_display_name_uses_the_joined_company_prefix(): void
    {
        $this->makeGroup('Zeta');
        $this->makeGroup('Alfa');

        $displayNames = array_map(
            fn ($r) => $r['display_name'],
            $this->groupsAs(['sort_by' => 'display_name', 'sort_dir' => 'asc'])['data']
        );

        $this->assertSame(['PFX_Alfa', 'PFX_Zeta'], $displayNames);
    }

    public function test_sorting_does_not_leak_another_companys_sales_groups(): void
    {
        $companyB = $this->makeCompany('BPFX');
        $moduleB = Module::where('key', 'sales_group')->first();
        $companyB->enabledModules()->attach($moduleB->id, ['enabled' => true]);

        app(CurrentCompany::class)->set($companyB->id);
        SalesGroup::create(['company_id' => $companyB->id, 'name' => 'Aaa Elsőnek Tűnne']);
        app(CurrentCompany::class)->clear();

        $this->makeGroup('Zzz Saját');

        $data = $this->groupsAs(['sort_by' => 'name', 'sort_dir' => 'asc']);

        $this->assertSame(1, $data['meta']['total']);
        $this->assertSame('Zzz Saját', $data['data'][0]['name']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function groupNames(array $query): array
    {
        return array_map(fn ($row) => $row['name'], $this->groupsAs($query)['data']);
    }

    private function groupsAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/sales-groups?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeGroup(string $name): SalesGroup
    {
        app(CurrentCompany::class)->set($this->company->id);
        $group = SalesGroup::create(['company_id' => $this->company->id, 'name' => $name]);
        app(CurrentCompany::class)->clear();

        return $group;
    }

    private function makeCompany(string $groupPrefix): Company
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
            'group_prefix'        => $groupPrefix,
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
