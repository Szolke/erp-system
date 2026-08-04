<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/groups — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `members`/`permissions` a withCount() SELECT-aliasára (`users_count`/
 * `permissions_count`) rendez — l. GroupController SORTABLE_COLUMNS.
 */
class GroupSortTest extends TestCase
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

    /** `members` a withCount('users') által generált users_count aliasra rendez. */
    public function test_sorting_by_members_uses_the_users_count_select_alias(): void
    {
        $smallGroup = $this->makeGroup('Kicsi');
        $bigGroup = $this->makeGroup('Nagy');

        $u1 = $this->makeUser();
        $u2 = $this->makeUser();
        $bigGroup->users()->attach([$u1->id, $u2->id]);

        $desc = $this->groupsAs(['sort_by' => 'members', 'sort_dir' => 'desc'])['data'];

        $this->assertSame('Nagy', $desc[0]['name']);
        $this->assertSame(2, $desc[0]['users_count']);
        $this->assertSame('Kicsi', $desc[1]['name']);
        $this->assertSame(0, $desc[1]['users_count']);
    }

    /** `permissions` a withCount('permissions') által generált permissions_count aliasra rendez. */
    public function test_sorting_by_permissions_uses_the_permissions_count_select_alias(): void
    {
        $groupA = $this->makeGroup('A csoport');
        $groupB = $this->makeGroup('B csoport');

        $perm = Permission::create(['key' => 'test.perm', 'module' => 'test', 'description' => 'x', 'is_sensitive' => false]);
        $groupB->permissions()->attach($perm->id);

        $asc = $this->groupsAs(['sort_by' => 'permissions', 'sort_dir' => 'asc'])['data'];

        $this->assertSame('A csoport', $asc[0]['name']);
        $this->assertSame(0, $asc[0]['permissions_count']);
        $this->assertSame('B csoport', $asc[1]['name']);
        $this->assertSame(1, $asc[1]['permissions_count']);
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
            ->getJson('/api/groups?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeGroup(string $name): Group
    {
        app(CurrentCompany::class)->set($this->company->id);
        $group = Group::create(['company_id' => $this->company->id, 'name' => $name]);
        app(CurrentCompany::class)->clear();

        return $group;
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
