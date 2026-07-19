<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VatRate;
use App\Services\InvoiceService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GET /api/documents — a SQL-ben SZÁRMAZTATOTT display_status (nem tárolt
 * oszlop, nincs migráció/job hozzá) és a HUF-normalizált summary blokk.
 */
class DocumentDisplayStatusTest extends TestCase
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
        Queue::fake();

        [$this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->series] = $this->makeCompanyFixtures('Bizonylat Kft.');

        $this->superadmin = User::create([
            'name' => 'Bizonylat Admin', 'email' => 'doc.admin.'.self::$seq.'@example.com',
            'password' => bcrypt('password'), 'is_superadmin' => true,
        ]);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Lejárt / határnap-eset
    // ══════════════════════════════════════════════════════════════════════════

    public function test_due_yesterday_and_unpaid_is_overdue(): void
    {
        $this->makeInvoice(['due_date' => now()->subDay()->toDateString(), 'payment_status' => PaymentStatus::Open]);

        $row = $this->firstRow();

        $this->assertSame('overdue', $row->display_status);
    }

    public function test_due_today_and_unpaid_is_not_overdue(): void
    {
        $this->makeInvoice(['due_date' => now()->toDateString(), 'payment_status' => PaymentStatus::Open]);

        $row = $this->firstRow();

        $this->assertSame('issued', $row->display_status, 'A határidő NAPJÁN még nem lejárt, csak az azt követő naptól');
    }

    public function test_due_yesterday_but_paid_is_paid_not_overdue(): void
    {
        $this->makeInvoice(['due_date' => now()->subDay()->toDateString(), 'payment_status' => PaymentStatus::Paid]);

        $row = $this->firstRow();

        $this->assertSame('paid', $row->display_status);
    }

    public function test_cancelled_invoice_is_never_overdue_even_if_long_overdue(): void
    {
        $invoice = $this->makeInvoice(['due_date' => now()->subYear()->toDateString(), 'payment_status' => PaymentStatus::Open]);

        app(CurrentCompany::class)->set($this->company->id);
        app(InvoiceService::class)->cancel($invoice, $this->superadmin);
        app(CurrentCompany::class)->clear();

        $rows = $this->allRows();
        $statuses = collect($rows)->pluck('display_status')->unique()->values()->all();

        $this->assertSame(['storno'], $statuses, 'Az eredeti ÉS a storno is display_status=storno');
    }

    public function test_receipt_is_never_overdue(): void
    {
        $this->makeReceipt(['issue_date' => now()->subYear()->toDateString()]);

        $row = $this->firstRow();

        $this->assertSame('issued', $row->display_status);
    }

    public function test_partial_payment_is_partially_paid_not_overdue(): void
    {
        $invoice = $this->makeInvoice([
            'due_date' => now()->subDay()->toDateString(), 'payment_status' => PaymentStatus::Partial, 'gross_total' => 20000,
        ]);
        Payment::create([
            'company_id' => $this->company->id, 'payable_type' => Invoice::class, 'payable_id' => $invoice->id,
            'payment_method_id' => $this->paymentMethod->id, 'amount' => 5000, 'currency' => 'HUF', 'paid_at' => now(),
        ]);

        $row = $this->firstRow();

        $this->assertSame('partially_paid', $row->display_status);
    }

    public function test_draft_invoice_is_draft_status(): void
    {
        $this->makeInvoice(['status' => InvoiceStatus::Draft, 'due_date' => now()->subDay()->toDateString(), 'payment_status' => PaymentStatus::Open]);

        $row = $this->firstRow();

        $this->assertSame('draft', $row->display_status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Szűrés
    // ══════════════════════════════════════════════════════════════════════════

    public function test_overdue_filter_returns_only_overdue_and_keeps_pagination_correct(): void
    {
        $this->makeInvoice(['due_date' => now()->subDay()->toDateString(), 'payment_status' => PaymentStatus::Open]); // overdue
        $this->makeInvoice(['due_date' => now()->addDay()->toDateString(), 'payment_status' => PaymentStatus::Open]); // issued
        $this->makeInvoice(['due_date' => now()->subDay()->toDateString(), 'payment_status' => PaymentStatus::Paid]); // paid

        $data = $this->documentsAs(['status' => 'overdue']);

        $this->assertSame(1, $data['meta']['total']);
        $this->assertCount(1, $data['data']);
        $this->assertSame('overdue', $data['data'][0]['display_status']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Összesítő
    // ══════════════════════════════════════════════════════════════════════════

    public function test_summary_counts_the_entire_filtered_set_not_just_the_current_page(): void
    {
        // A perPage() csak egy fix whitelistet fogad el (20/50/100/...) — a
        // lapozás-vs-összesítő különbséghez a whitelist legkisebb értékénél
        // (20) is több sort kell létrehozni.
        for ($i = 0; $i < 21; $i++) {
            $this->makeInvoice(['gross_total' => 1000]);
        }

        $data = $this->documentsAs(['per_page' => 20]);

        $this->assertCount(20, $data['data'], 'Az oldal csak a per_page szerinti sorszámot adja');
        $this->assertSame(21, $data['summary']['count'], 'Az összesítő a TELJES szűrt halmazra vonatkozik');
        $this->assertEqualsWithDelta(21000.0, $data['summary']['gross_total_huf'], 0.001);
    }

    public function test_summary_nets_out_storno_signed(): void
    {
        $invoice = $this->makeInvoice(['gross_total' => 12700, 'net_total' => 10000, 'vat_total' => 2700]);

        app(CurrentCompany::class)->set($this->company->id);
        app(InvoiceService::class)->cancel($invoice, $this->superadmin);
        app(CurrentCompany::class)->clear();

        $data = $this->documentsAs([]);

        $this->assertEqualsWithDelta(0.0, $data['summary']['gross_total_huf'], 0.001);
    }

    public function test_summary_converts_eur_invoice_to_huf(): void
    {
        $this->makeInvoice(['currency' => 'EUR', 'exchange_rate' => 400.0, 'gross_total' => 100]);

        $data = $this->documentsAs([]);

        $this->assertEqualsWithDelta(40000.0, $data['summary']['gross_total_huf'], 0.001);
        $this->assertSame(0, $data['summary']['skipped_count']);
    }

    public function test_summary_skips_invoice_with_missing_exchange_rate_and_reports_it(): void
    {
        DB::table('invoices')->insert([
            'company_id' => $this->company->id, 'partner_id' => $this->partner->id, 'document_series_id' => $this->series->id,
            'invoice_number' => 'BROKEN-0001', 'issue_date' => now()->toDateString(), 'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(), 'currency' => 'EUR', 'exchange_rate' => 0,
            'exchange_rate_date' => now()->toDateString(), 'payment_method_id' => $this->paymentMethod->id,
            'status' => 'issued', 'payment_status' => 'open', 'net_total' => 100, 'vat_total' => 27, 'gross_total' => 127,
            'gross_total_base_currency' => 127, 'nav_status' => 'not_applicable', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->documentsAs([]);

        $this->assertEqualsWithDelta(0.0, $data['summary']['gross_total_huf'], 0.001);
        $this->assertSame(1, $data['summary']['skipped_count']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Multi-company izoláció
    // ══════════════════════════════════════════════════════════════════════════

    public function test_other_companys_documents_do_not_leak_into_list_or_summary(): void
    {
        [$companyB, $partnerB, $pmB, $vatB, $seriesB] = $this->makeCompanyFixtures('Bizonylat Isol Kft.');
        $this->makeInvoiceFor($companyB, $partnerB, $pmB, $vatB, $seriesB, ['gross_total' => 999999]);
        $this->makeInvoice(['gross_total' => 1000]);

        $data = $this->documentsAs([]);

        $this->assertSame(1, $data['meta']['total']);
        $this->assertEqualsWithDelta(1000.0, $data['summary']['gross_total_huf'], 0.001);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function firstRow(): object
    {
        return (object) $this->allRows()[0];
    }

    private function allRows(): array
    {
        return $this->documentsAs([])['data'];
    }

    private function documentsAs(array $query): array
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/documents?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    /** @return array{0: Company, 1: Partner, 2: PaymentMethod, 3: VatRate, 4: DocumentSeries} */
    private function makeCompanyFixtures(string $namePrefix): array
    {
        self::$seq++;

        $company = Company::create([
            'name' => $namePrefix.' '.self::$seq,
            'tax_number' => '8888888'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'Bizonylat u. '.self::$seq.'.',
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($company->id);

        $partner = Partner::create([
            'type' => 'customer', 'name' => 'Bizonylat Partner '.self::$seq,
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        $paymentMethod = PaymentMethod::create(['code' => 'DOCCASH'.self::$seq, 'name' => 'Készpénz', 'is_active' => true]);
        $vatRate = VatRate::create(['name' => 'ÁFA 27% '.self::$seq, 'rate_percent' => 27.00, 'nav_code' => '27', 'is_active' => true]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'document_type' => DocumentType::Invoice,
            'prefix' => 'DC'.self::$seq, 'reset_yearly' => true, 'next_number' => 1,
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
            'invoice_number' => sprintf('DC-%s-%06d', now()->format('Ym'), self::$docSeq),
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
            'receipt_number' => sprintf('DCNY-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => now()->toDateString(),
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
}
