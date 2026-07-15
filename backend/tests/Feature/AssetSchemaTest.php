<?php

namespace Tests\Feature;

use App\Models\Company;
use Database\Seeders\AssetTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Assets module — 2. lépés: schema-level coverage (no model/API yet).
 *
 * Covers:
 *   1. Tables and expected columns exist.
 *   2. assets: composite unique constraints (name, serial_number, imei — NULL-safe).
 *   3. asset_types: partial unique index for global (company_id IS NULL) codes,
 *      vs. per-company code uniqueness (same code allowed as a company-owned row).
 *   4. asset_number_counters: composite unique (company_id, asset_type_id).
 *   5. AssetTypeSeeder seeds exactly the 3 global base types.
 */
class AssetSchemaTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    // ══════════════════════════════════════════════════════════════════════════
    // 1. Tables and columns exist
    // ══════════════════════════════════════════════════════════════════════════

    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('asset_types'));
        $this->assertTrue(Schema::hasColumns('asset_types', ['id', 'company_id', 'code', 'name', 'created_at', 'updated_at']));

        $this->assertTrue(Schema::hasTable('assets'));
        $this->assertTrue(Schema::hasColumns('assets', [
            'id', 'company_id', 'name', 'serial_number', 'imei', 'asset_type_id', 'status', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('asset_number_counters'));
        $this->assertTrue(Schema::hasColumns('asset_number_counters', [
            'id', 'company_id', 'asset_type_id', 'next_seq', 'created_at', 'updated_at',
        ]));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. assets — composite unique constraints
    // ══════════════════════════════════════════════════════════════════════════

    public function test_duplicate_company_name_fails(): void
    {
        $company = $this->makeCompany();
        $type    = $this->makeAssetType($company);

        $this->insertAsset($company->id, $type, 'DEMO_TEYA_000001', 'SN-1');

        $this->expectException(QueryException::class);
        $this->insertAsset($company->id, $type, 'DEMO_TEYA_000001', 'SN-2');
    }

    public function test_duplicate_company_serial_number_fails(): void
    {
        $company = $this->makeCompany();
        $type    = $this->makeAssetType($company);

        $this->insertAsset($company->id, $type, 'DEMO_TEYA_000001', 'SN-DUP');

        $this->expectException(QueryException::class);
        $this->insertAsset($company->id, $type, 'DEMO_TEYA_000002', 'SN-DUP');
    }

    public function test_two_null_imei_allowed_for_same_company(): void
    {
        $company = $this->makeCompany();
        $type    = $this->makeAssetType($company);

        $this->insertAsset($company->id, $type, 'DEMO_TEYA_000001', 'SN-A', imei: null);
        $this->insertAsset($company->id, $type, 'DEMO_TEYA_000002', 'SN-B', imei: null);

        $this->assertSame(2, DB::table('assets')->where('company_id', $company->id)->count());
    }

    public function test_duplicate_non_null_imei_fails(): void
    {
        $company = $this->makeCompany();
        $type    = $this->makeAssetType($company);

        $this->insertAsset($company->id, $type, 'DEMO_TEYA_000001', 'SN-A', imei: '123456789012345');

        $this->expectException(QueryException::class);
        $this->insertAsset($company->id, $type, 'DEMO_TEYA_000002', 'SN-B', imei: '123456789012345');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. asset_types — global partial unique vs. per-company uniqueness
    // ══════════════════════════════════════════════════════════════════════════

    public function test_duplicate_global_code_fails(): void
    {
        DB::table('asset_types')->insert([
            'company_id' => null, 'code' => 'CUSTOM', 'name' => 'Custom A',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('asset_types')->insert([
            'company_id' => null, 'code' => 'CUSTOM', 'name' => 'Custom B',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_same_code_as_company_owned_row_is_allowed(): void
    {
        $company = $this->makeCompany();

        DB::table('asset_types')->insert([
            'company_id' => null, 'code' => 'TEYA-LOCAL', 'name' => 'Global Teya',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Same code, but company-owned — the partial index only covers company_id IS NULL,
        // so this must succeed (this is the whole point of the partial index, not a plain
        // composite unique).
        DB::table('asset_types')->insert([
            'company_id' => $company->id, 'code' => 'TEYA-LOCAL', 'name' => 'Company Teya',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(
            2,
            DB::table('asset_types')->where('code', 'TEYA-LOCAL')->count()
        );
    }

    public function test_duplicate_code_within_same_company_fails(): void
    {
        $company = $this->makeCompany();

        DB::table('asset_types')->insert([
            'company_id' => $company->id, 'code' => 'LOCAL', 'name' => 'Local A',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('asset_types')->insert([
            'company_id' => $company->id, 'code' => 'LOCAL', 'name' => 'Local B',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. asset_number_counters — composite unique (company_id, asset_type_id)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_duplicate_counter_row_for_same_company_and_type_fails(): void
    {
        $company = $this->makeCompany();
        $type    = $this->makeAssetType($company);

        DB::table('asset_number_counters')->insert([
            'company_id' => $company->id, 'asset_type_id' => $type, 'next_seq' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('asset_number_counters')->insert([
            'company_id' => $company->id, 'asset_type_id' => $type, 'next_seq' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. AssetTypeSeeder — global base types
    // ══════════════════════════════════════════════════════════════════════════

    public function test_seeder_creates_exactly_the_three_global_base_types(): void
    {
        (new AssetTypeSeeder())->run();

        $rows = DB::table('asset_types')->whereNull('company_id')->orderBy('code')->get();

        $this->assertCount(3, $rows);
        $this->assertSame(['MOBIL', 'PRINTER', 'TEYA'], $rows->pluck('code')->all());
    }

    public function test_seeder_is_idempotent(): void
    {
        (new AssetTypeSeeder())->run();
        (new AssetTypeSeeder())->run();

        $this->assertSame(
            3,
            DB::table('asset_types')->whereNull('company_id')->count(),
            'Re-running the seeder must not create duplicate global rows'
        );
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

    private function makeAssetType(Company $company): int
    {
        self::$seq++;
        return DB::table('asset_types')->insertGetId([
            'company_id' => $company->id,
            'code'       => 'TYPE'.self::$seq,
            'name'       => 'Type '.self::$seq,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAsset(int $companyId, int $assetTypeId, string $name, string $serialNumber, ?string $imei = null): void
    {
        DB::table('assets')->insert([
            'company_id'    => $companyId,
            'name'          => $name,
            'serial_number' => $serialNumber,
            'imei'          => $imei,
            'asset_type_id' => $assetTypeId,
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }
}
