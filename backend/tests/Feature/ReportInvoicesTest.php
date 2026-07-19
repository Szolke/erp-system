<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VatRate;
use App\Services\InvoiceService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GET /api/reports/invoices — a ReportService közös szabályainak (piszkozat-
 * kizárás, storno-előjel, deviza-normalizálás, date_basis, üres időszak)
 * end-to-end lefedettsége.
 */
class ReportInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    private Company $company;
    private Partner $partner;
    private PaymentMethod $paymentMethod;
    private VatRate $vatRate;
    private DocumentSeries $series;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();
        // RefreshDatabase a Postgres-t resetálja, a Redist NEM — l. ReportGatingTest.
        \Illuminate\Support\Facades\Cache::store('redis')->tags(['reports'])->flush();
        Queue::fake();

        [$this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->series] = $this->makeCompanyFixtures('Riport Kft.');

        $module = Module::create([
            'key' => 'reports', 'name' => 'Kimutatások', 'description' => 'test',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 20,
        ]);
        $this->company->enabledModules()->attach($module->id, ['enabled' => true]);

        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Piszkozat-kizárás
    // ══════════════════════════════════════════════════════════════════════════

    public function test_draft_invoice_is_excluded(): void
    {
        $this->makeInvoice(['status' => InvoiceStatus::Draft, 'issue_date' => '2026-03-10', 'fulfillment_date' => '2026-03-10']);

        $data = $this->reportAs('2026-03', '2026-03');

        $this->assertSame(0, $data['periods'][0]['invoice_count']);
        // json_decode kerek 0.0-t egésszé "lapítja" (l. docs/progress.md Nyitott pont #16) — assertEquals kell.
        $this->assertEquals(0.0, $data['periods'][0]['gross_total']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Storno-előjel
    // ══════════════════════════════════════════════════════════════════════════

    public function test_storno_and_original_net_to_zero_within_the_same_period(): void
    {
        $invoice = $this->makeInvoice([
            'issue_date' => '2026-04-05', 'fulfillment_date' => '2026-04-05', 'gross_total' => 12700,
            'net_total' => 10000, 'vat_total' => 2700,
        ]);

        Carbon::setTestNow('2026-04-10');
        app(CurrentCompany::class)->set($this->company->id);
        app(InvoiceService::class)->cancel($invoice, $this->superadmin);
        app(CurrentCompany::class)->clear();

        $data = $this->reportAs('2026-04', '2026-04');

        $this->assertSame(2, $data['periods'][0]['invoice_count'], 'Az eredeti ÉS a storno is számít bizonylatként');
        $this->assertEqualsWithDelta(0.0, $data['periods'][0]['gross_total'], 0.001, 'A storno kioltja az eredetit');
        $this->assertEqualsWithDelta(0.0, $data['periods'][0]['net_total'], 0.001);
        $this->assertEqualsWithDelta(0.0, $data['periods'][0]['vat_total'], 0.001);
    }

    public function test_storno_created_in_a_later_period_reverses_revenue_in_its_own_period(): void
    {
        $invoice = $this->makeInvoice([
            'issue_date' => '2026-05-05', 'fulfillment_date' => '2026-05-05', 'gross_total' => 12700,
            'net_total' => 10000, 'vat_total' => 2700,
        ]);

        Carbon::setTestNow('2026-06-15'); // a sztornó egy KÉSŐBBI hónapban készül
        app(CurrentCompany::class)->set($this->company->id);
        app(InvoiceService::class)->cancel($invoice, $this->superadmin);
        app(CurrentCompany::class)->clear();

        $data = $this->reportAs('2026-05', '2026-06');

        $this->assertEqualsWithDelta(12700.0, $data['periods'][0]['gross_total'], 0.001, 'Május: csak az eredeti számít');
        $this->assertEqualsWithDelta(-12700.0, $data['periods'][1]['gross_total'], 0.001, 'Június: a storno önmagában negatív');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Deviza-normalizálás
    // ══════════════════════════════════════════════════════════════════════════

    public function test_eur_invoice_is_converted_to_huf_using_the_stored_exchange_rate(): void
    {
        $this->makeInvoice([
            'issue_date' => '2026-07-05', 'fulfillment_date' => '2026-07-05',
            'currency' => 'EUR', 'exchange_rate' => 400.0,
            'net_total' => 100, 'vat_total' => 27, 'gross_total' => 127,
        ]);

        $data = $this->reportAs('2026-07', '2026-07');

        $this->assertEqualsWithDelta(50800.0, $data['periods'][0]['gross_total'], 0.001);
        $this->assertSame(0, $data['warnings']['skipped_count']);
    }

    public function test_invalid_exchange_rate_is_skipped_and_counted_as_a_warning(): void
    {
        // Az exchange_rate oszlop DB-szinten NOT NULL, normál API-forgalomból nem
        // fordulhat elő hiányzó/érvénytelen érték — közvetlen DB-beszúrással
        // szimuláljuk a védekező skip-ágat.
        DB::table('invoices')->insert([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'document_series_id' => $this->series->id,
            'invoice_number' => 'BROKEN-0001',
            'issue_date' => '2026-08-05',
            'fulfillment_date' => '2026-08-05',
            'due_date' => '2026-08-19',
            'currency' => 'EUR',
            'exchange_rate' => 0,
            'exchange_rate_date' => '2026-08-05',
            'payment_method_id' => $this->paymentMethod->id,
            'status' => 'issued',
            'payment_status' => 'open',
            'net_total' => 100,
            'vat_total' => 27,
            'gross_total' => 127,
            'gross_total_base_currency' => 127,
            'nav_status' => 'not_applicable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = $this->reportAs('2026-08', '2026-08');

        $this->assertSame(0, $data['periods'][0]['invoice_count'], 'A hibás árfolyamú sor kimarad az összegekből');
        $this->assertEqualsWithDelta(0.0, $data['periods'][0]['gross_total'], 0.001);
        $this->assertSame(1, $data['warnings']['skipped_count']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Üres időszak
    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_month_with_no_invoices_appears_with_zero_values(): void
    {
        $this->makeInvoice(['issue_date' => '2026-01-10', 'fulfillment_date' => '2026-01-10']);

        $data = $this->reportAs('2026-01', '2026-03');

        $this->assertCount(3, $data['periods']);
        $this->assertSame('2026-02', $data['periods'][1]['period']);
        $this->assertSame(0, $data['periods'][1]['invoice_count']);
        $this->assertEquals(0.0, $data['periods'][1]['gross_total']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // date_basis
    // ══════════════════════════════════════════════════════════════════════════

    public function test_date_basis_fulfillment_puts_year_end_invoice_in_december(): void
    {
        $this->makeInvoice(['issue_date' => '2026-01-03', 'fulfillment_date' => '2025-12-31']);

        $data = $this->reportAs('2025-12', '2025-12', dateBasis: 'fulfillment');

        $this->assertSame(1, $data['periods'][0]['invoice_count']);
    }

    public function test_date_basis_issue_puts_the_same_invoice_in_january(): void
    {
        $this->makeInvoice(['issue_date' => '2026-01-03', 'fulfillment_date' => '2025-12-31']);

        $data = $this->reportAs('2026-01', '2026-01', dateBasis: 'issue');

        $this->assertSame(1, $data['periods'][0]['invoice_count']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // include_receipts
    // ══════════════════════════════════════════════════════════════════════════

    public function test_include_receipts_reports_receipt_totals_in_separate_fields(): void
    {
        $this->makeInvoice(['issue_date' => '2026-09-05', 'fulfillment_date' => '2026-09-05', 'gross_total' => 1000, 'net_total' => 787, 'vat_total' => 213]);
        $this->makeReceipt(['issue_date' => '2026-09-06', 'fulfillment_date' => '2026-09-06', 'gross_total' => 500]);

        $data = $this->reportAs('2026-09', '2026-09', includeReceipts: true);

        $period = $data['periods'][0];
        $this->assertEqualsWithDelta(1000.0, $period['gross_total'], 0.001, 'A számla-összeg nem keveredhet a nyugtával');
        $this->assertSame(1, $period['receipt_count']);
        $this->assertEqualsWithDelta(500.0, $period['receipt_gross_total'], 0.001);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Szűrők + multi-company izoláció
    // ══════════════════════════════════════════════════════════════════════════

    public function test_partner_filter_excludes_other_partners_invoices(): void
    {
        $otherPartner = Partner::withoutGlobalScope('company')->create([
            'company_id' => $this->company->id, 'type' => 'customer', 'name' => 'Másik partner',
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 2.',
            'default_currency' => 'HUF',
        ]);

        $this->makeInvoice(['issue_date' => '2026-10-05', 'fulfillment_date' => '2026-10-05', 'gross_total' => 1000]);
        $this->makeInvoice(['issue_date' => '2026-10-06', 'fulfillment_date' => '2026-10-06', 'gross_total' => 5000, 'partner_id' => $otherPartner->id]);

        $data = $this->reportAs('2026-10', '2026-10', partnerId: $this->partner->id);

        $this->assertEqualsWithDelta(1000.0, $data['periods'][0]['gross_total'], 0.001);
    }

    public function test_status_filter_only_counts_matching_payment_status(): void
    {
        $this->makeInvoice(['issue_date' => '2026-11-05', 'fulfillment_date' => '2026-11-05', 'gross_total' => 1000, 'payment_status' => PaymentStatus::Open]);
        $this->makeInvoice(['issue_date' => '2026-11-06', 'fulfillment_date' => '2026-11-06', 'gross_total' => 2000, 'payment_status' => PaymentStatus::Paid]);

        $data = $this->reportAs('2026-11', '2026-11', status: 'paid');

        $this->assertEqualsWithDelta(2000.0, $data['periods'][0]['gross_total'], 0.001);
        $this->assertSame(1, $data['periods'][0]['invoice_count']);
    }

    public function test_other_companys_invoices_do_not_leak_into_the_report(): void
    {
        [$companyB, $partnerB, $pmB, $vatB, $seriesB] = $this->makeCompanyFixtures('Riport Isol Kft.');
        $this->makeInvoiceFor($companyB, $partnerB, $pmB, $vatB, $seriesB, [
            'issue_date' => '2026-12-05', 'fulfillment_date' => '2026-12-05', 'gross_total' => 999999,
        ]);
        $this->makeInvoice(['issue_date' => '2026-12-05', 'fulfillment_date' => '2026-12-05', 'gross_total' => 1000]);

        $data = $this->reportAs('2026-12', '2026-12');

        $this->assertEqualsWithDelta(1000.0, $data['periods'][0]['gross_total'], 0.001);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function reportAs(
        string $from,
        string $to,
        string $granularity = 'month',
        string $dateBasis = 'fulfillment',
        ?int $partnerId = null,
        ?string $status = null,
        bool $includeReceipts = false,
    ): array {
        $query = ['from' => $from, 'to' => $to, 'granularity' => $granularity, 'date_basis' => $dateBasis];
        if ($partnerId !== null) $query['partner_id'] = $partnerId;
        if ($status !== null) $query['status'] = $status;
        if ($includeReceipts) $query['include_receipts'] = '1';

        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/reports/invoices?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    /** @return array{0: Company, 1: Partner, 2: PaymentMethod, 3: VatRate, 4: DocumentSeries} */
    private function makeCompanyFixtures(string $namePrefix): array
    {
        self::$seq++;

        $company = Company::create([
            'name' => $namePrefix.' '.self::$seq,
            'tax_number' => '3333333'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'Riport u. '.self::$seq.'.',
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($company->id);

        $partner = Partner::create([
            'type' => 'customer', 'name' => 'Riport Partner '.self::$seq,
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        $paymentMethod = PaymentMethod::create(['code' => 'RIPCASH'.self::$seq, 'name' => 'Készpénz', 'is_active' => true]);
        $vatRate = VatRate::create(['name' => 'ÁFA 27% '.self::$seq, 'rate_percent' => 27.00, 'nav_code' => '27', 'is_active' => true]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'document_type' => DocumentType::Invoice,
            'prefix' => 'RP'.self::$seq, 'reset_yearly' => true, 'next_number' => 1,
        ]);

        app(CurrentCompany::class)->clear();

        return [$company, $partner, $paymentMethod, $vatRate, $series];
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return $this->makeInvoiceFor($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->series, $overrides);
    }

    private function makeInvoiceFor(
        Company $company, Partner $partner, PaymentMethod $paymentMethod, VatRate $vatRate, DocumentSeries $series, array $overrides = []
    ): Invoice {
        self::$docSeq++;

        $grossTotal = $overrides['gross_total'] ?? 1270.0;
        $netTotal = $overrides['net_total'] ?? round($grossTotal / 1.27, 2);
        $vatTotal = $overrides['vat_total'] ?? round($grossTotal - $netTotal, 2);

        $invoice = Invoice::create(array_merge([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => sprintf('RP-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => $netTotal,
            'vat_total' => $vatTotal,
            'gross_total' => $grossTotal,
            'gross_total_base_currency' => $grossTotal,
        ], $overrides));

        $invoice->items()->create([
            'description' => 'Teszt tétel', 'quantity' => 1.0, 'unit' => 'db',
            'unit_price' => (float) $invoice->net_total, 'vat_rate_id' => $vatRate->id,
            'net_amount' => (float) $invoice->net_total, 'vat_amount' => (float) $invoice->vat_total,
            'gross_amount' => (float) $invoice->gross_total, 'sort_order' => 0,
        ]);

        return $invoice;
    }

    private function makeReceipt(array $overrides = []): Receipt
    {
        self::$docSeq++;
        $grossTotal = $overrides['gross_total'] ?? 500.0;

        return Receipt::create(array_merge([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'document_series_id' => $this->series->id,
            'receipt_number' => sprintf('RPNY-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'status' => 'issued',
            'net_total' => round($grossTotal / 1.27, 2),
            'vat_total' => round($grossTotal - $grossTotal / 1.27, 2),
            'gross_total' => $grossTotal,
        ], $overrides));
    }

    private function makeUser(bool $superadmin = false): User
    {
        self::$seq++;

        return User::create([
            'name' => 'Riport User '.self::$seq,
            'email' => 'report.invoices.'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }
}
