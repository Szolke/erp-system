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
use App\Models\User;
use App\Models\VatRate;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** GET /api/reports/vat-summary — hónap × ÁFA-kategória bontás, adómentes kategóriákkal. */
class ReportVatSummaryTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    private Company $company;
    private Partner $partner;
    private PaymentMethod $paymentMethod;
    private DocumentSeries $series;
    private User $superadmin;
    private VatRate $vat27;
    private VatRate $vatAam;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();
        // RefreshDatabase a Postgres-t resetálja, a Redist NEM — l. ReportGatingTest.
        \Illuminate\Support\Facades\Cache::store('redis')->tags(['reports'])->flush();
        Queue::fake();

        self::$seq++;
        $this->company = Company::create([
            'name' => 'ÁFA Kft. '.self::$seq,
            'tax_number' => '4444444'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'ÁFA u. 1.',
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($this->company->id);
        $this->partner = Partner::create([
            'type' => 'customer', 'name' => 'ÁFA Partner',
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);
        $this->paymentMethod = PaymentMethod::create(['code' => 'VATCASH'.self::$seq, 'name' => 'Készpénz', 'is_active' => true]);
        $this->vat27 = VatRate::create(['name' => '27% normál '.self::$seq, 'rate_percent' => 27.00, 'nav_code' => '0.27', 'is_active' => true]);
        $this->vatAam = VatRate::create(['name' => 'Alanyi adómentes (AAM) '.self::$seq, 'rate_percent' => null, 'nav_code' => 'AAM', 'is_active' => true]);
        $this->series = DocumentSeries::create([
            'document_type' => DocumentType::Invoice, 'prefix' => 'VT'.self::$seq, 'reset_yearly' => true, 'next_number' => 1,
        ]);
        app(CurrentCompany::class)->clear();

        $module = Module::create([
            'key' => 'reports', 'name' => 'Kimutatások', 'description' => 'test',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 20,
        ]);
        $this->company->enabledModules()->attach($module->id, ['enabled' => true]);

        $this->superadmin = User::create([
            'name' => 'ÁFA Admin', 'email' => 'report.vat.'.self::$seq.'@example.com',
            'password' => bcrypt('password'), 'is_superadmin' => true,
        ]);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_groups_by_month_and_vat_category(): void
    {
        $this->makeInvoiceWithItem($this->vat27, ['net_amount' => 10000, 'vat_amount' => 2700, 'gross_amount' => 12700], '2026-03-05');
        $this->makeInvoiceWithItem($this->vatAam, ['net_amount' => 5000, 'vat_amount' => 0, 'gross_amount' => 5000], '2026-03-06');

        $data = $this->reportAs('2026-03', '2026-03');

        $this->assertCount(2, $data['items']);
        $categories = collect($data['items'])->pluck('vat_category')->all();
        $this->assertContains('27% normál '.self::$seq, $categories);
        $this->assertContains('Alanyi adómentes (AAM) '.self::$seq, $categories);
    }

    public function test_exempt_category_carries_its_nav_code(): void
    {
        $this->makeInvoiceWithItem($this->vatAam, ['net_amount' => 5000, 'vat_amount' => 0, 'gross_amount' => 5000], '2026-04-05');

        $data = $this->reportAs('2026-04', '2026-04');

        $this->assertSame('AAM', $data['items'][0]['nav_code']);
        $this->assertEqualsWithDelta(0.0, $data['items'][0]['vat_total'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $data['items'][0]['net_total'], 0.001);
    }

    public function test_totals_aggregate_by_category_across_periods_and_grand_total(): void
    {
        $this->makeInvoiceWithItem($this->vat27, ['net_amount' => 10000, 'vat_amount' => 2700, 'gross_amount' => 12700], '2026-05-05');
        $this->makeInvoiceWithItem($this->vat27, ['net_amount' => 20000, 'vat_amount' => 5400, 'gross_amount' => 25400], '2026-06-05');

        $data = $this->reportAs('2026-05', '2026-06');

        $this->assertCount(1, $data['totals']['by_category']);
        $this->assertEqualsWithDelta(30000.0, $data['totals']['by_category'][0]['net_total'], 0.001);
        $this->assertEqualsWithDelta(38100.0, $data['totals']['grand_total']['gross_total'], 0.001);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function reportAs(string $from, string $to): array
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/reports/vat-summary?'.http_build_query(['from' => $from, 'to' => $to]))
            ->assertOk()
            ->json();
    }

    private function makeInvoiceWithItem(VatRate $vatRate, array $item, string $date): Invoice
    {
        self::$docSeq++;

        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'document_series_id' => $this->series->id,
            'invoice_number' => sprintf('VT-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => $date,
            'fulfillment_date' => $date,
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => $date,
            'payment_method_id' => $this->paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => $item['net_amount'],
            'vat_total' => $item['vat_amount'],
            'gross_total' => $item['gross_amount'],
            'gross_total_base_currency' => $item['gross_amount'],
        ]);

        $invoice->items()->create(array_merge([
            'description' => 'Teszt tétel', 'quantity' => 1.0, 'unit' => 'db',
            'unit_price' => $item['net_amount'], 'vat_rate_id' => $vatRate->id, 'sort_order' => 0,
        ], $item));

        return $invoice;
    }
}
