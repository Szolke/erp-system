<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assets module — 3. lépés: model-layer coverage (AssetStatus cast, AssetType
 * "global OR mine" visibility scope, Asset multi-tenant isolation, canBeDeleted()).
 */
class AssetModelTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // AssetStatus cast
    // ══════════════════════════════════════════════════════════════════════════

    public function test_status_casts_to_asset_status_enum(): void
    {
        $company = $this->makeCompany();
        $type    = $this->makeAssetType();
        app(CurrentCompany::class)->set($company->id);

        $asset = Asset::create([
            'company_id'    => $company->id,
            'name'          => 'DEMO_TEYA_00001',
            'serial_number' => 'SN-1',
            'asset_type_id' => $type->id,
            'status'        => AssetStatus::Issued,
        ]);

        $this->assertInstanceOf(AssetStatus::class, $asset->fresh()->status);
        $this->assertSame(AssetStatus::Issued, $asset->fresh()->status);
    }

    public function test_default_status_is_active_when_not_set(): void
    {
        $company = $this->makeCompany();
        $type    = $this->makeAssetType();
        app(CurrentCompany::class)->set($company->id);

        $asset = Asset::create([
            'company_id'    => $company->id,
            'name'          => 'DEMO_TEYA_00002',
            'serial_number' => 'SN-2',
            'asset_type_id' => $type->id,
        ]);

        $this->assertSame(AssetStatus::Active, $asset->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // AssetType — "global OR mine" visibility scope
    // ══════════════════════════════════════════════════════════════════════════

    public function test_global_asset_type_visible_to_every_company(): void
    {
        $companyA = $this->makeCompany();
        $global   = $this->makeAssetType(companyId: null);

        app(CurrentCompany::class)->set($companyA->id);

        $this->assertTrue(AssetType::query()->whereKey($global->id)->exists());
    }

    public function test_own_company_asset_type_visible(): void
    {
        $companyA = $this->makeCompany();
        $ownType  = $this->makeAssetType(companyId: $companyA->id);

        app(CurrentCompany::class)->set($companyA->id);

        $this->assertTrue(AssetType::query()->whereKey($ownType->id)->exists());
    }

    public function test_other_company_asset_type_not_visible(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $bType    = $this->makeAssetType(companyId: $companyB->id);

        app(CurrentCompany::class)->set($companyA->id);

        $this->assertFalse(AssetType::query()->whereKey($bType->id)->exists());
    }

    public function test_asset_type_does_not_auto_fill_company_id_on_create(): void
    {
        $company = $this->makeCompany();
        app(CurrentCompany::class)->set($company->id);

        // Unlike BelongsToCompany models, AssetType must NOT silently become
        // company-owned just because a company is active in context.
        $type = AssetType::create(['code' => 'NOAUTO', 'name' => 'No auto-fill']);

        $this->assertNull($type->fresh()->company_id);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Asset — multi-tenant isolation (BelongsToCompany)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_asset_not_visible_from_other_company_context(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $type     = $this->makeAssetType();

        app(CurrentCompany::class)->set($companyA->id);
        $asset = Asset::create([
            'company_id'    => $companyA->id,
            'name'          => 'DEMO_TEYA_00003',
            'serial_number' => 'SN-3',
            'asset_type_id' => $type->id,
        ]);

        app(CurrentCompany::class)->set($companyB->id);

        $this->assertFalse(Asset::query()->whereKey($asset->id)->exists());
    }

    // ══════════════════════════════════════════════════════════════════════════
    // canBeDeleted()
    // ══════════════════════════════════════════════════════════════════════════

    public function test_can_be_deleted_is_currently_always_true(): void
    {
        $company = $this->makeCompany();
        $type    = $this->makeAssetType();
        app(CurrentCompany::class)->set($company->id);

        $asset = Asset::create([
            'company_id'    => $company->id,
            'name'          => 'DEMO_TEYA_00004',
            'serial_number' => 'SN-4',
            'asset_type_id' => $type->id,
        ]);

        $this->assertTrue($asset->canBeDeleted());
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function makeCompany(): Company
    {
        self::$seq++;
        return Company::withoutGlobalScope('company')->create([
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

    private function makeAssetType(?int $companyId = null): AssetType
    {
        self::$seq++;

        return AssetType::create([
            'company_id' => $companyId,
            'code'       => 'TYPE'.self::$seq,
            'name'       => 'Type '.self::$seq,
        ]);
    }
}
