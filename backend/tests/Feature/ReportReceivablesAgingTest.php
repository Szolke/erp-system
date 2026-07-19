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
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\VatRate;
use App\Services\InvoiceService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GET /api/reports/receivables-aging — sávhatár-esetek (30 vs. 31 nap),
 * részfizetés-maradvány, és a sztornózott (jelenleg fennálló tartozásként
 * NEM releváns) számla kizárása — l. ReportService osztály-docblock 2. pont
 * kivétele.
 */
class ReportReceivablesAgingTest extends TestCase
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

        self::$seq++;
        $this->company = Company::create([
            'name' => 'Korosítás Kft. '.self::$seq,
            'tax_number' => '1111111'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'Korosítás u. 1.',
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($this->company->id);
        $this->partner = Partner::create([
            'type' => 'customer', 'name' => 'Korosítás Partner',
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);
        $this->paymentMethod = PaymentMethod::create(['code' => 'AGECASH'.self::$seq, 'name' => 'Készpénz', 'is_active' => true]);
        $this->vatRate = VatRate::create(['name' => 'ÁFA 27% '.self::$seq, 'rate_percent' => 27.00, 'nav_code' => '27', 'is_active' => true]);
        $this->series = DocumentSeries::create([
            'document_type' => DocumentType::Invoice, 'prefix' => 'AG'.self::$seq, 'reset_yearly' => true, 'next_number' => 1,
        ]);
        app(CurrentCompany::class)->clear();

        $module = Module::create([
            'key' => 'reports', 'name' => 'Kimutatások', 'description' => 'test',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 20,
        ]);
        $this->company->enabledModules()->attach($module->id, ['enabled' => true]);

        $this->superadmin = User::create([
            'name' => 'Korosítás Admin', 'email' => 'report.aging.'.self::$seq.'@example.com',
            'password' => bcrypt('password'), 'is_superadmin' => true,
        ]);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_exactly_30_days_overdue_falls_into_the_0_30_band(): void
    {
        $this->makeInvoice(['due_date' => '2026-05-01', 'gross_total' => 1000]);

        $data = $this->reportAs('2026-05-31'); // 2026-05-31 - 2026-05-01 = 30 nap

        $this->assertEqualsWithDelta(1000.0, $data['totals']['band_0_30'], 0.001);
        $this->assertEqualsWithDelta(0.0, $data['totals']['band_31_60'], 0.001);
    }

    public function test_exactly_31_days_overdue_falls_into_the_31_60_band(): void
    {
        $this->makeInvoice(['due_date' => '2026-05-01', 'gross_total' => 1000]);

        $data = $this->reportAs('2026-06-01'); // 31 nap

        $this->assertEqualsWithDelta(0.0, $data['totals']['band_0_30'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $data['totals']['band_31_60'], 0.001);
    }

    public function test_future_due_date_falls_into_not_due(): void
    {
        $this->makeInvoice(['due_date' => '2026-08-01', 'gross_total' => 1000]);

        $data = $this->reportAs('2026-07-01');

        $this->assertEqualsWithDelta(1000.0, $data['totals']['not_due'], 0.001);
    }

    public function test_partial_payment_reduces_the_remaining_amount(): void
    {
        $invoice = $this->makeInvoice(['due_date' => '2026-05-01', 'gross_total' => 20000, 'payment_status' => PaymentStatus::Partial]);
        Payment::create([
            'company_id' => $this->company->id, 'payable_type' => Invoice::class, 'payable_id' => $invoice->id,
            'payment_method_id' => $this->paymentMethod->id, 'amount' => 12000, 'currency' => 'HUF', 'paid_at' => now(),
        ]);

        $data = $this->reportAs('2026-05-15');

        $this->assertEqualsWithDelta(8000.0, $data['totals']['total'], 0.001);
    }

    public function test_cancelled_invoice_is_excluded_even_though_payment_status_stays_stale(): void
    {
        // Sztornó után az eredeti számla payment_status mezője változatlan marad
        // (l. docs/progress.md Nyitott pont #13) — a korosításnak ennek ellenére
        // ki KELL zárnia, mert a tartozás ténylegesen érvénytelenítve lett.
        $invoice = $this->makeInvoice(['due_date' => '2026-05-01', 'gross_total' => 20000]);

        app(CurrentCompany::class)->set($this->company->id);
        app(InvoiceService::class)->cancel($invoice, $this->superadmin);
        app(CurrentCompany::class)->clear();

        $data = $this->reportAs('2026-06-01');

        $this->assertSame([], $data['partners']);
        $this->assertEqualsWithDelta(0.0, $data['totals']['total'], 0.001);
    }

    public function test_paid_invoice_is_excluded(): void
    {
        $this->makeInvoice(['due_date' => '2026-05-01', 'gross_total' => 1000, 'payment_status' => PaymentStatus::Paid]);

        $data = $this->reportAs('2026-06-01');

        $this->assertSame([], $data['partners']);
    }

    public function test_other_companys_invoices_do_not_leak_into_the_aging_report(): void
    {
        self::$seq++;
        $companyB = Company::create([
            'name' => 'Korosítás Isol Kft. '.self::$seq,
            'tax_number' => '9999999'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'Izoláció u. 1.',
            'base_currency' => 'HUF',
        ]);
        app(CurrentCompany::class)->set($companyB->id);
        $partnerB = Partner::create([
            'type' => 'customer', 'name' => 'Izolált Partner',
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 2.',
            'default_currency' => 'HUF',
        ]);
        $pmB = PaymentMethod::create(['code' => 'ISOCASH'.self::$seq, 'name' => 'Készpénz', 'is_active' => true]);
        $vatB = VatRate::create(['name' => 'ÁFA B '.self::$seq, 'rate_percent' => 27.00, 'nav_code' => '27', 'is_active' => true]);
        $seriesB = DocumentSeries::create(['document_type' => DocumentType::Invoice, 'prefix' => 'ISB'.self::$seq, 'reset_yearly' => true, 'next_number' => 1]);
        app(CurrentCompany::class)->clear();

        $this->makeInvoiceFor($companyB, $partnerB, $pmB, $vatB, $seriesB, ['due_date' => '2026-05-01', 'gross_total' => 999999]);
        $this->makeInvoice(['due_date' => '2026-05-01', 'gross_total' => 1000]);

        $data = $this->reportAs('2026-05-15');

        $this->assertEqualsWithDelta(1000.0, $data['totals']['total'], 0.001);
        $this->assertCount(1, $data['partners']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function reportAs(string $asOf, ?int $partnerId = null): array
    {
        $query = ['as_of' => $asOf];
        if ($partnerId !== null) $query['partner_id'] = $partnerId;

        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/reports/receivables-aging?'.http_build_query($query))
            ->assertOk()
            ->json();
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
        $netTotal = round($grossTotal / 1.27, 2);
        $vatTotal = round($grossTotal - $netTotal, 2);

        $invoice = Invoice::create(array_merge([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => sprintf('AG-%s-%06d', now()->format('Ym'), self::$docSeq),
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
}
