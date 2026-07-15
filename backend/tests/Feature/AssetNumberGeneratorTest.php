<?php

namespace Tests\Feature;

use App\Models\AssetNumberCounter;
use App\Models\AssetType;
use App\Models\Company;
use App\Services\AssetNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assets module — 3. lépés: AssetNumberGenerator coverage, mirroring
 * InvoiceNumberGeneratorTest's structure (see that file). Gaps are allowed
 * here (unlike invoices), so there is no yearly-reset equivalent to test.
 */
class AssetNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    // --- Formátum / alap sorrend ---

    public function test_first_name_uses_seq_one_zero_padded_to_five_digits(): void
    {
        $company = $this->makeCompany('DEMO');
        $type    = $this->makeAssetType('TEYA');

        [, $name] = app(AssetNumberGenerator::class)->next($company->id, $type->id, $type->code);

        $this->assertSame('DEMO_TEYA_00001', $name);
    }

    public function test_consecutive_calls_increment_sequentially(): void
    {
        $company = $this->makeCompany('DEMO');
        $type    = $this->makeAssetType('TEYA');
        $gen     = app(AssetNumberGenerator::class);

        [, $n1] = $gen->next($company->id, $type->id, $type->code);
        [, $n2] = $gen->next($company->id, $type->id, $type->code);
        [, $n3] = $gen->next($company->id, $type->id, $type->code);

        $this->assertSame('DEMO_TEYA_00001', $n1);
        $this->assertSame('DEMO_TEYA_00002', $n2);
        $this->assertSame('DEMO_TEYA_00003', $n3);
    }

    // --- Cégenkénti / típusonkénti izoláció ---

    public function test_counters_are_isolated_per_company(): void
    {
        $companyA = $this->makeCompany('ACME');
        $companyB = $this->makeCompany('BETA');
        $type     = $this->makeAssetType('MOBIL');
        $gen      = app(AssetNumberGenerator::class);

        $gen->next($companyA->id, $type->id, $type->code);
        $gen->next($companyA->id, $type->id, $type->code);

        [, $bFirst] = $gen->next($companyB->id, $type->id, $type->code);

        $this->assertSame('BETA_MOBIL_00001', $bFirst);
    }

    public function test_counters_are_isolated_per_asset_type(): void
    {
        $company = $this->makeCompany('DEMO');
        $teya    = $this->makeAssetType('TEYA');
        $mobil   = $this->makeAssetType('MOBIL');
        $gen     = app(AssetNumberGenerator::class);

        $gen->next($company->id, $teya->id, $teya->code);
        $gen->next($company->id, $teya->id, $teya->code);

        [, $mobilFirst] = $gen->next($company->id, $mobil->id, $mobil->code);

        $this->assertSame('DEMO_MOBIL_00001', $mobilFirst);
    }

    // --- Create-on-first-use + perzisztencia ---

    public function test_counter_row_is_created_on_first_use(): void
    {
        $company = $this->makeCompany('DEMO');
        $type    = $this->makeAssetType('TEYA');

        $this->assertSame(0, AssetNumberCounter::withoutGlobalScope('company')->count());

        app(AssetNumberGenerator::class)->next($company->id, $type->id, $type->code);

        $counter = AssetNumberCounter::withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('asset_type_id', $type->id)
            ->first();

        $this->assertNotNull($counter);
        $this->assertSame(2, $counter->next_seq);
    }

    public function test_uses_existing_counter_instead_of_creating_new(): void
    {
        $company = $this->makeCompany('DEMO');
        $type    = $this->makeAssetType('TEYA');

        AssetNumberCounter::withoutGlobalScope('company')->create([
            'company_id'    => $company->id,
            'asset_type_id' => $type->id,
            'next_seq'      => 7,
        ]);

        [, $name] = app(AssetNumberGenerator::class)->next($company->id, $type->id, $type->code);

        $this->assertSame('DEMO_TEYA_00007', $name);
    }

    // --- Hiányzó cég-prefix ---

    public function test_throws_when_company_has_no_group_prefix(): void
    {
        $company = Company::withoutGlobalScope('company')->create($this->companyAttributes(null));
        $type    = $this->makeAssetType('TEYA');

        $this->expectException(\RuntimeException::class);
        app(AssetNumberGenerator::class)->next($company->id, $type->id, $type->code);
    }

    // --- Helpers ---

    private function makeCompany(string $groupPrefix): Company
    {
        return Company::withoutGlobalScope('company')->create($this->companyAttributes($groupPrefix));
    }

    private function companyAttributes(?string $groupPrefix): array
    {
        self::$seq++;

        return [
            'name'                => 'Company '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-03',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
            'group_prefix'        => $groupPrefix,
        ];
    }

    private function makeAssetType(string $code): AssetType
    {
        return AssetType::create([
            'company_id' => null,
            'code'       => $code,
            'name'       => $code,
        ]);
    }
}
