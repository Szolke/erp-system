<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\ReceiptReportStatus;
use App\Enums\ReceiptReportType;
use App\Enums\ReceiptStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptReport;
use App\Models\VatRate;
use App\Services\Enyugta\ReceiptReportBuildException;
use App\Services\Enyugta\ReceiptReportBuilder;
use App\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ReceiptReportBuilder — a napi nyugta-összesítő aggregáció tesztjei (NAV
 * eNyugta 2. fázis). Fedi: egy/több áfakulcs, nullás nap, sztornó nyugta, D4
 * (hiányzó nav_receipt_category), nem-HUF hiba, D1 (korrekció), idempotencia,
 * a receipt_reports_one_normal_per_day parciális unique index.
 */
class ReceiptReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    private Company $company;
    private PaymentMethod $paymentMethod;
    private DocumentSeries $receiptSeries;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        self::$seq++;

        $this->company = Company::withoutGlobalScope('company')->create([
            'name'                => 'eNyugta Report Kft. '.self::$seq,
            'tax_number'          => '3216547'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Jelentés u. '.self::$seq.'.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        app(CurrentCompany::class)->set($this->company->id);

        $this->paymentMethod = PaymentMethod::create([
            'code'      => 'CASH'.self::$seq,
            'name'      => 'Készpénz',
            'is_active' => true,
        ]);

        $this->receiptSeries = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id'    => $this->company->id,
            'document_type' => DocumentType::Receipt,
            'prefix'        => 'NY',
            'reset_yearly'  => true,
            'next_number'   => 1,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Aggregáció
    // ══════════════════════════════════════════════════════════════════════

    public function test_single_vat_rate_aggregates_into_one_line(): void
    {
        $vatRate = $this->makeVatRate('27%', '27%');
        $this->makeReceipt('2026-07-10', [[$vatRate, 10000, 2700, 12700]]);
        $this->makeReceipt('2026-07-10', [[$vatRate, 5000, 1350, 6350]]);

        $report = app(ReceiptReportBuilder::class)->build($this->company, now()->parse('2026-07-10'));

        $this->assertSame(ReceiptReportType::Normal, $report->type);
        $this->assertSame(2, $report->receipt_count);
        $this->assertSame('15000.00', (string) $report->total_net);
        $this->assertSame('4050.00', (string) $report->total_vat);
        $this->assertSame('19050.00', (string) $report->total_gross);

        $lines = $report->lines()->get();
        $this->assertCount(1, $lines);
        $this->assertSame('27%', $lines->first()->nav_receipt_category);
        $this->assertSame(2, $lines->first()->receipt_count);
    }

    public function test_multiple_vat_rates_aggregate_into_separate_lines(): void
    {
        $vat27 = $this->makeVatRate('27%', '27%');
        $vat5 = $this->makeVatRate('5%', '5%');

        $this->makeReceipt('2026-07-10', [
            [$vat27, 10000, 2700, 12700],
            [$vat5, 2000, 100, 2100],
        ]);

        $report = app(ReceiptReportBuilder::class)->build($this->company, now()->parse('2026-07-10'));

        $lines = $report->lines()->get()->keyBy('nav_receipt_category');
        $this->assertCount(2, $lines);
        $this->assertSame('10000.00', (string) $lines['27%']->net_amount);
        $this->assertSame('2000.00', (string) $lines['5%']->net_amount);
        // Egy nyugta, ami MINDKÉT kategóriában szerepel — a fejléc receipt_count
        // NEM a sorok összege (ami 2 lenne), hanem a napon szereplő nyugták száma.
        $this->assertSame(1, $report->receipt_count);
        $this->assertSame(1, $lines['27%']->receipt_count);
        $this->assertSame(1, $lines['5%']->receipt_count);
    }

    public function test_zero_receipt_day_creates_report_with_no_lines(): void
    {
        $report = app(ReceiptReportBuilder::class)->build($this->company, now()->parse('2026-07-10'));

        $this->assertSame(ReceiptReportType::Normal, $report->type);
        $this->assertSame(0, $report->receipt_count);
        $this->assertSame('0.00', (string) $report->total_gross);
        $this->assertCount(0, $report->lines()->get());
    }

    public function test_storno_receipt_included_with_negative_amounts(): void
    {
        $vatRate = $this->makeVatRate('27%', '27%');
        $this->makeReceipt('2026-07-10', [[$vatRate, 10000, 2700, 12700]]);
        // A sztornó a saját (mai) kiállítási dátumával kerül a napba — negatív tételekkel.
        $this->makeReceipt('2026-07-10', [[$vatRate, -10000, -2700, -12700]], ReceiptStatus::Storno);

        $report = app(ReceiptReportBuilder::class)->build($this->company, now()->parse('2026-07-10'));

        $this->assertSame(2, $report->receipt_count);
        $this->assertSame('0.00', (string) $report->total_net, 'A sztornó kioltja az eredetit');
        $this->assertSame('0.00', (string) $report->total_gross);
    }

    // ══════════════════════════════════════════════════════════════════════
    // D4 — hiányzó áfa-megfeleltetés
    // ══════════════════════════════════════════════════════════════════════

    public function test_missing_nav_receipt_category_throws_clean_error(): void
    {
        $vatRate = $this->makeVatRate('27% jelöletlen', null);
        $this->makeReceipt('2026-07-10', [[$vatRate, 10000, 2700, 12700]]);

        $this->expectException(ReceiptReportBuildException::class);
        $this->expectExceptionMessageMatches('/nav_receipt_category/');

        app(ReceiptReportBuilder::class)->build($this->company, now()->parse('2026-07-10'));
    }

    public function test_non_huf_receipt_throws_clean_error(): void
    {
        $vatRate = $this->makeVatRate('27%', '27%');
        $this->makeReceipt('2026-07-10', [[$vatRate, 10000, 2700, 12700]], ReceiptStatus::Issued, 'EUR');

        $this->expectException(ReceiptReportBuildException::class);
        $this->expectExceptionMessageMatches('/HUF/');

        app(ReceiptReportBuilder::class)->build($this->company, now()->parse('2026-07-10'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // D1 — korrekció
    // ══════════════════════════════════════════════════════════════════════

    public function test_correction_created_when_accepted_report_changes(): void
    {
        $vatRate = $this->makeVatRate('27%', '27%');
        $this->makeReceipt('2026-07-10', [[$vatRate, 10000, 2700, 12700]]);

        $builder = app(ReceiptReportBuilder::class);
        $original = $builder->build($this->company, now()->parse('2026-07-10'));
        $original->update(['status' => ReceiptReportStatus::Accepted]);

        // Időközi változás: egy újabb nyugta jelenik meg ugyanarra a napra.
        $this->makeReceipt('2026-07-10', [[$vatRate, 3000, 810, 3810]]);

        $correction = $builder->build($this->company, now()->parse('2026-07-10'));

        $this->assertSame(ReceiptReportType::Correction, $correction->type);
        $this->assertSame($original->id, $correction->original_report_id);
        $this->assertSame(2, $correction->receipt_count);
        $this->assertSame('13000.00', (string) $correction->total_net, 'A korrekció a nap TELJES újraszámolt összesítése, nem a különbözet');

        $original->refresh();
        $this->assertSame(ReceiptReportStatus::Accepted, $original->status, 'Az eredeti jelentés állapota érintetlen marad');
        $this->assertSame(1, $original->receipt_count, 'Az eredeti jelentés SAJÁT adatai (materializált sorai) nem íródnak felül');
    }

    public function test_idempotent_when_accepted_report_has_no_changes(): void
    {
        $vatRate = $this->makeVatRate('27%', '27%');
        $this->makeReceipt('2026-07-10', [[$vatRate, 10000, 2700, 12700]]);

        $builder = app(ReceiptReportBuilder::class);
        $original = $builder->build($this->company, now()->parse('2026-07-10'));
        $original->update(['status' => ReceiptReportStatus::Accepted]);

        $result = $builder->build($this->company, now()->parse('2026-07-10'));

        $this->assertSame($original->id, $result->id, 'Nincs változás → nincs korrekció, ugyanaz a jelentés jön vissza');
        $this->assertSame(1, ReceiptReport::where('company_id', $this->company->id)->count());
    }

    public function test_draft_report_regenerated_in_place_without_creating_new_row(): void
    {
        $vatRate = $this->makeVatRate('27%', '27%');
        $this->makeReceipt('2026-07-10', [[$vatRate, 10000, 2700, 12700]]);

        $builder = app(ReceiptReportBuilder::class);
        $first = $builder->build($this->company, now()->parse('2026-07-10'));
        $this->assertSame(ReceiptReportStatus::Draft, $first->status);

        $this->makeReceipt('2026-07-10', [[$vatRate, 3000, 810, 3810]]);
        $second = $builder->build($this->company, now()->parse('2026-07-10'));

        $this->assertSame($first->id, $second->id, 'Még el nem fogadott jelentés helyben frissül, nem korrekció jön létre');
        $this->assertSame(2, $second->receipt_count);
        $this->assertSame(1, ReceiptReport::where('company_id', $this->company->id)->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Parciális unique index
    // ══════════════════════════════════════════════════════════════════════

    public function test_second_normal_report_for_same_day_is_rejected_at_db_level(): void
    {
        $this->makeVatRate('27%', '27%');

        DB::table('receipt_reports')->insert([
            'company_id' => $this->company->id,
            'report_date' => '2026-07-10',
            'type' => 'normal',
            'status' => 'draft',
            'receipt_count' => 0,
            'total_net' => 0,
            'total_vat' => 0,
            'total_gross' => 0,
            'retry_count' => 0,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('receipt_reports')->insert([
            'company_id' => $this->company->id,
            'report_date' => '2026-07-10',
            'type' => 'normal',
            'status' => 'draft',
            'receipt_count' => 0,
            'total_net' => 0,
            'total_vat' => 0,
            'total_gross' => 0,
            'retry_count' => 0,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_second_correction_for_same_day_is_allowed_at_db_level(): void
    {
        $normalId = DB::table('receipt_reports')->insertGetId([
            'company_id' => $this->company->id,
            'report_date' => '2026-07-10',
            'type' => 'normal',
            'status' => 'accepted',
            'receipt_count' => 0,
            'total_net' => 0,
            'total_vat' => 0,
            'total_gross' => 0,
            'retry_count' => 0,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([1, 2] as $i) {
            DB::table('receipt_reports')->insert([
                'company_id' => $this->company->id,
                'report_date' => '2026-07-10',
                'type' => 'correction',
                'original_report_id' => $normalId,
                'status' => 'draft',
                'receipt_count' => 0,
                'total_net' => 0,
                'total_vat' => 0,
                'total_gross' => 0,
                'retry_count' => 0,
                'generated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('receipt_reports')->where('type', 'correction')->count());
    }

    public function test_check_constraint_rejects_correction_without_original_report_id(): void
    {
        $this->expectException(QueryException::class);

        DB::table('receipt_reports')->insert([
            'company_id' => $this->company->id,
            'report_date' => '2026-07-11',
            'type' => 'correction',
            'original_report_id' => null,
            'status' => 'draft',
            'receipt_count' => 0,
            'total_net' => 0,
            'total_vat' => 0,
            'total_gross' => 0,
            'retry_count' => 0,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_check_constraint_rejects_normal_report_with_original_report_id(): void
    {
        $normalId = DB::table('receipt_reports')->insertGetId([
            'company_id' => $this->company->id,
            'report_date' => '2026-07-11',
            'type' => 'normal',
            'status' => 'draft',
            'receipt_count' => 0,
            'total_net' => 0,
            'total_vat' => 0,
            'total_gross' => 0,
            'retry_count' => 0,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        // Más napra, hogy a parciális unique index (egy normál/nap) ne akadjon
        // el előbb, mint a CHECK constraint — ezt kifejezetten a CHECK
        // constraint hivatott elkapni: normal + kitöltött original_report_id.
        DB::table('receipt_reports')->insert([
            'company_id' => $this->company->id,
            'report_date' => '2026-07-12',
            'type' => 'normal',
            'original_report_id' => $normalId,
            'status' => 'draft',
            'receipt_count' => 0,
            'total_net' => 0,
            'total_vat' => 0,
            'total_gross' => 0,
            'retry_count' => 0,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    private function makeVatRate(string $name, ?string $navReceiptCategory, float $ratePercent = 27.0): VatRate
    {
        self::$seq++;

        return VatRate::create([
            'name' => $name.' '.self::$seq,
            'rate_percent' => $ratePercent,
            'nav_code' => 'CODE'.self::$seq,
            'nav_receipt_category' => $navReceiptCategory,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array{0: VatRate, 1: float, 2: float, 3: float}>  $items  [vatRate, net, vat, gross]
     */
    private function makeReceipt(string $issueDate, array $items, ReceiptStatus $status = ReceiptStatus::Issued, string $currency = 'HUF'): Receipt
    {
        self::$docSeq++;

        $netTotal = array_sum(array_column($items, 1));
        $vatTotal = array_sum(array_column($items, 2));
        $grossTotal = array_sum(array_column($items, 3));

        $receipt = Receipt::create([
            'company_id' => $this->company->id,
            'document_series_id' => $this->receiptSeries->id,
            'receipt_number' => sprintf('NY-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => $issueDate,
            'currency' => $currency,
            'exchange_rate' => 1.0,
            'exchange_rate_date' => $issueDate,
            'payment_method_id' => $this->paymentMethod->id,
            'status' => $status,
            'net_total' => $netTotal,
            'vat_total' => $vatTotal,
            'gross_total' => $grossTotal,
        ]);

        foreach ($items as $index => [$vatRate, $net, $vat, $gross]) {
            $receipt->items()->create([
                'description' => 'Teszt tétel '.($index + 1),
                'quantity' => 1,
                'unit_price' => $net,
                'vat_rate_id' => $vatRate->id,
                'net_amount' => $net,
                'vat_amount' => $vat,
                'gross_amount' => $gross,
                'sort_order' => $index,
            ]);
        }

        return $receipt;
    }
}
