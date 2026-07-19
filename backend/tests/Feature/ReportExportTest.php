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

/** GET /api/reports/{report}/export?format=csv — CSV-mechanika (BOM, elválasztó, sorszám, fájlnév). */
class ReportExportTest extends TestCase
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
            'name' => 'Export Kft. '.self::$seq,
            'tax_number' => '6666666'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'Export u. 1.',
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($this->company->id);
        $this->partner = Partner::create([
            'type' => 'customer', 'name' => 'Export Partner',
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);
        $this->paymentMethod = PaymentMethod::create(['code' => 'EXPCASH'.self::$seq, 'name' => 'Készpénz', 'is_active' => true]);
        $this->vatRate = VatRate::create(['name' => 'ÁFA 27% '.self::$seq, 'rate_percent' => 27.00, 'nav_code' => '27', 'is_active' => true]);
        $this->series = DocumentSeries::create([
            'document_type' => DocumentType::Invoice, 'prefix' => 'EX'.self::$seq, 'reset_yearly' => true, 'next_number' => 1,
        ]);
        app(CurrentCompany::class)->clear();

        $module = Module::create([
            'key' => 'reports', 'name' => 'Kimutatások', 'description' => 'test',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 20,
        ]);
        $this->company->enabledModules()->attach($module->id, ['enabled' => true]);

        $this->superadmin = User::create([
            'name' => 'Export Admin', 'email' => 'report.export.'.self::$seq.'@example.com',
            'password' => bcrypt('password'), 'is_superadmin' => true,
        ]);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_csv_starts_with_utf8_bom(): void
    {
        $this->makeInvoice('2026-03-05', 1000);

        $content = $this->exportContent('invoices', ['from' => '2026-03', 'to' => '2026-03']);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
    }

    public function test_csv_uses_semicolon_as_delimiter(): void
    {
        $this->makeInvoice('2026-04-05', 1000);

        $content = $this->exportContent('invoices', ['from' => '2026-04', 'to' => '2026-04']);
        $headerLine = strtok(ltrim($content, "\xEF\xBB\xBF"), "\n");

        $this->assertStringContainsString(';', $headerLine);
        $this->assertStringNotContainsString(',', $headerLine);
    }

    public function test_csv_row_count_matches_the_json_response(): void
    {
        $this->makeInvoice('2026-01-05', 1000);
        $this->makeInvoice('2026-03-05', 2000);

        $json = $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/reports/invoices?'.http_build_query(['from' => '2026-01', 'to' => '2026-03']))
            ->assertOk()
            ->json();

        $content = $this->exportContent('invoices', ['from' => '2026-01', 'to' => '2026-03']);
        $lines = array_filter(explode("\n", trim(ltrim($content, "\xEF\xBB\xBF"))));

        // header + 3 hónap (üres hónapok is sorként jelennek meg)
        $this->assertCount(count($json['periods']) + 1, $lines);
    }

    public function test_filename_follows_the_reports_report_from_to_pattern(): void
    {
        $this->makeInvoice('2026-05-05', 1000);

        $response = $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->get('/api/reports/invoices/export?'.http_build_query(['from' => '2026-05', 'to' => '2026-05']));

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('reports-invoices-2026-05-2026-05.csv', $disposition);
    }

    public function test_aging_export_filename_falls_back_to_a_single_export_without_as_of(): void
    {
        $this->makeInvoice('2026-06-05', 1000);

        $response = $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->get('/api/reports/receivables-aging/export'); // se from/to, se as_of

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('reports-receivables-aging-export.csv', $disposition);
        $this->assertStringNotContainsString('export-export', $disposition);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function exportContent(string $report, array $query): string
    {
        $response = $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->get("/api/reports/{$report}/export?".http_build_query($query));

        $response->assertOk();

        return $response->streamedContent();
    }

    private function makeInvoice(string $date, float $grossTotal): Invoice
    {
        self::$docSeq++;
        $netTotal = round($grossTotal / 1.27, 2);
        $vatTotal = round($grossTotal - $netTotal, 2);

        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'document_series_id' => $this->series->id,
            'invoice_number' => sprintf('EX-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => $date,
            'fulfillment_date' => $date,
            'due_date' => $date,
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => $date,
            'payment_method_id' => $this->paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => $netTotal,
            'vat_total' => $vatTotal,
            'gross_total' => $grossTotal,
            'gross_total_base_currency' => $grossTotal,
        ]);

        $invoice->items()->create([
            'description' => 'Teszt tétel', 'quantity' => 1.0, 'unit' => 'db',
            'unit_price' => $netTotal, 'vat_rate_id' => $this->vatRate->id,
            'net_amount' => $netTotal, 'vat_amount' => $vatTotal, 'gross_amount' => $grossTotal, 'sort_order' => 0,
        ]);

        return $invoice;
    }
}
