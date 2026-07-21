<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\ReceiptStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Module;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptReport;
use App\Models\VatRate;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * erp:build-receipt-reports parancs tesztjei: modul-gating (csak enyugta
 * modullal rendelkező cégre fut), idempotencia (kétszeri futtatás után a
 * jelentések SZÁMA változatlan), D4 hiba esetén tiszta hibaüzenet + exit 1.
 */
class BuildReceiptReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_command_only_processes_companies_with_enyugta_module_enabled(): void
    {
        $enabledCompany = $this->makeCompany();
        $this->enableEnyugtaModule($enabledCompany);
        $this->seedReceiptDay($enabledCompany, '2026-07-10');

        $disabledCompany = $this->makeCompany();
        $this->seedReceiptDay($disabledCompany, '2026-07-10');

        $this->artisan('erp:build-receipt-reports', ['--date' => '2026-07-10'])
            ->assertSuccessful();

        $this->assertSame(1, ReceiptReport::withoutGlobalScope('company')->where('company_id', $enabledCompany->id)->count());
        $this->assertSame(0, ReceiptReport::withoutGlobalScope('company')->where('company_id', $disabledCompany->id)->count());
    }

    public function test_command_is_idempotent_report_count_unchanged_on_second_run(): void
    {
        $company = $this->makeCompany();
        $this->enableEnyugtaModule($company);
        $this->seedReceiptDay($company, '2026-07-10');

        $this->artisan('erp:build-receipt-reports', ['--date' => '2026-07-10'])->assertSuccessful();
        $firstCount = ReceiptReport::withoutGlobalScope('company')->where('company_id', $company->id)->count();

        $this->artisan('erp:build-receipt-reports', ['--date' => '2026-07-10'])->assertSuccessful();
        $secondCount = ReceiptReport::withoutGlobalScope('company')->where('company_id', $company->id)->count();

        $this->assertSame(1, $firstCount);
        $this->assertSame($firstCount, $secondCount);
    }

    public function test_command_exits_with_failure_and_clean_message_on_missing_vat_category_mapping(): void
    {
        $company = $this->makeCompany();
        $this->enableEnyugtaModule($company);
        $this->seedReceiptDay($company, '2026-07-10', navReceiptCategory: null);

        $this->artisan('erp:build-receipt-reports', ['--date' => '2026-07-10'])
            ->assertFailed();

        $this->assertSame(0, ReceiptReport::withoutGlobalScope('company')->where('company_id', $company->id)->count());
    }

    public function test_command_respects_company_option(): void
    {
        $company = $this->makeCompany();
        $this->enableEnyugtaModule($company);
        $this->seedReceiptDay($company, '2026-07-10');

        $otherCompany = $this->makeCompany();
        $this->enableEnyugtaModule($otherCompany);
        $this->seedReceiptDay($otherCompany, '2026-07-10');

        $this->artisan('erp:build-receipt-reports', ['--date' => '2026-07-10', '--company' => $company->id])
            ->assertSuccessful();

        $this->assertSame(1, ReceiptReport::withoutGlobalScope('company')->where('company_id', $company->id)->count());
        $this->assertSame(0, ReceiptReport::withoutGlobalScope('company')->where('company_id', $otherCompany->id)->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::withoutGlobalScope('company')->create([
            'name'                => 'eNyugta Cmd Kft. '.self::$seq,
            'tax_number'          => '5556667'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Parancs u. '.self::$seq.'.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }

    private function enableEnyugtaModule(Company $company): void
    {
        $module = Module::firstOrCreate(
            ['key' => 'enyugta'],
            ['name' => 'eNyugta', 'description' => 'test', 'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10]
        );
        $company->enabledModules()->attach($module->id, ['enabled' => true]);
    }

    private function seedReceiptDay(Company $company, string $issueDate, ?string $navReceiptCategory = '27%'): void
    {
        app(CurrentCompany::class)->set($company->id);

        self::$seq++;

        $paymentMethod = PaymentMethod::create([
            'code' => 'CASH'.self::$seq,
            'name' => 'Készpénz',
            'is_active' => true,
        ]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'document_type' => DocumentType::Receipt,
            'prefix' => 'NY',
            'reset_yearly' => true,
            'next_number' => 1,
        ]);

        $vatRate = VatRate::create([
            'name' => '27% '.self::$seq,
            'rate_percent' => 27.0,
            'nav_code' => 'CODE'.self::$seq,
            'nav_receipt_category' => $navReceiptCategory,
            'is_active' => true,
        ]);

        self::$docSeq++;

        $receipt = Receipt::create([
            'company_id' => $company->id,
            'document_series_id' => $series->id,
            'receipt_number' => sprintf('NY-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => $issueDate,
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => $issueDate,
            'payment_method_id' => $paymentMethod->id,
            'status' => ReceiptStatus::Issued,
            'net_total' => 10000,
            'vat_total' => 2700,
            'gross_total' => 12700,
        ]);

        $receipt->items()->create([
            'description' => 'Teszt tétel',
            'quantity' => 1,
            'unit_price' => 10000,
            'vat_rate_id' => $vatRate->id,
            'net_amount' => 10000,
            'vat_amount' => 2700,
            'gross_amount' => 12700,
            'sort_order' => 0,
        ]);

        app(CurrentCompany::class)->clear();
    }
}
