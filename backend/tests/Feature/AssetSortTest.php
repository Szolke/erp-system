<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Company;
use App\Models\Module;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/assets — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * Az `asset_type` a kapcsolt asset_types.name-re rendez, ehhez az index()
 * MINDIG joinolja az asset_types táblát — l. AssetController SORTABLE_COLUMNS.
 */
class AssetSortTest extends TestCase
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
        $type = $this->makeType('T1', 'Típus');
        $this->makeAsset('Zeta', $type);
        $this->makeAsset('Alfa', $type);

        $this->assertSame(['Alfa', 'Zeta'], $this->namesAs([]));
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $type = $this->makeType('T1', 'Típus');
        $this->makeAsset('Zeta', $type);
        $this->makeAsset('Alfa', $type);

        $names = $this->namesAs(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'desc']);

        $this->assertSame(['Alfa', 'Zeta'], $names);
    }

    /** `asset_type` a kapcsolt asset_types.name-re rendez — ez a JOIN-specifikus eset. */
    public function test_sorting_by_asset_type_uses_the_joined_column(): void
    {
        $typeZ = $this->makeType('TZ', 'Zeta típus');
        $typeA = $this->makeType('TA', 'Alfa típus');

        $this->makeAsset('Eszköz B', $typeZ);
        $this->makeAsset('Eszköz A', $typeA);

        $types = array_map(
            fn ($r) => $r['asset_type']['name'],
            $this->assetsAs(['sort_by' => 'asset_type', 'sort_dir' => 'asc'])['data']
        );

        $this->assertSame(['Alfa típus', 'Zeta típus'], $types);
    }

    public function test_sorting_by_serial_number_descending(): void
    {
        $type = $this->makeType('T1', 'Típus');
        $this->makeAsset('A', $type, 'SN-1');
        $this->makeAsset('B', $type, 'SN-3');
        $this->makeAsset('C', $type, 'SN-2');

        $serials = array_map(
            fn ($r) => $r['serial_number'],
            $this->assetsAs(['sort_by' => 'serial_number', 'sort_dir' => 'desc'])['data']
        );

        $this->assertSame(['SN-3', 'SN-2', 'SN-1'], $serials);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function namesAs(array $query): array
    {
        return array_map(fn ($row) => $row['name'], $this->assetsAs($query)['data']);
    }

    private function assetsAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/assets?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeType(string $code, string $name): AssetType
    {
        return AssetType::create(['company_id' => null, 'code' => $code, 'name' => $name]);
    }

    private function makeAsset(string $name, AssetType $type, ?string $serial = null): Asset
    {
        app(CurrentCompany::class)->set($this->company->id);

        $asset = Asset::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'serial_number' => $serial ?? 'SN-'.$name,
            'asset_type_id' => $type->id,
            'status' => 'active',
        ]);

        app(CurrentCompany::class)->clear();

        return $asset;
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
