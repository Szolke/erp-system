<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PermissionEffect;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VatRate;
use App\Services\InvoiceService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GET /api/documents/export?format=csv — ugyanaz a szűrt halmaz, mint a
 * listánál (nincs lapozás), CSV-mechanika (BOM, elválasztó), és a
 * jogosultság (invoice.view/receipt.view — ugyanaz, mint a listáé).
 */
class DocumentExportTest extends TestCase
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

        [$this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->series] = $this->makeCompanyFixtures('Export Kft.');

        $this->superadmin = User::create([
            'name' => 'Export Admin', 'email' => 'doc.export.'.self::$seq.'@example.com',
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
    // Halmaz-egyezés a listával
    // ══════════════════════════════════════════════════════════════════════════

    public function test_export_row_count_matches_json_list_regardless_of_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeInvoice(['gross_total' => 1000]);
        }

        $json = $this->documentsAs(['per_page' => 20]);
        $this->assertCount(20, $json['data'], 'a lista lapozott — csak 20 sor az oldalon');
        $this->assertSame(25, $json['meta']['total'], 'a szűrt halmaz teljes mérete 25');

        $lines = $this->exportLines([]);
        // fejléc + 25 adatsor — az export NEM lapoz
        $this->assertCount(26, $lines);
    }

    public function test_export_applies_the_same_filters_as_the_list(): void
    {
        $this->makeInvoice(['due_date' => now()->subDay()->toDateString(), 'payment_status' => PaymentStatus::Open]); // overdue
        $this->makeInvoice(['due_date' => now()->addDay()->toDateString(), 'payment_status' => PaymentStatus::Open]); // issued
        $this->makeReceipt();

        $lines = $this->exportLines(['status' => 'overdue']);

        $this->assertCount(2, $lines, 'fejléc + 1 lejárt sor');
        $this->assertStringContainsString('Lejárt', $lines[1]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CSV-mechanika
    // ══════════════════════════════════════════════════════════════════════════

    public function test_csv_starts_with_utf8_bom(): void
    {
        $this->makeInvoice();

        $content = $this->exportContent([]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
    }

    public function test_csv_uses_semicolon_as_delimiter_and_hungarian_headers(): void
    {
        $this->makeInvoice();

        $content = $this->exportContent([]);
        $headerLine = strtok(ltrim($content, "\xEF\xBB\xBF"), "\n");

        $this->assertStringContainsString(';', $headerLine);
        $this->assertStringNotContainsString(',', $headerLine);
        $this->assertStringContainsString('Bizonylatszám', $headerLine);
        $this->assertStringContainsString('Bruttó (HUF)', $headerLine);
        $this->assertStringContainsString('Állapot', $headerLine);
    }

    public function test_filename_follows_the_documents_from_to_pattern(): void
    {
        $this->makeInvoice();

        $response = $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->get('/api/documents/export?'.http_build_query(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']));

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('bizonylatok-2026-01-01-2026-01-31.csv', $disposition);
    }

    public function test_filename_falls_back_to_a_single_export_without_date_range(): void
    {
        $this->makeInvoice();

        $response = $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->get('/api/documents/export');

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('bizonylatok-export.csv', $disposition);
        $this->assertStringNotContainsString('export-export', $disposition);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Sztornó előjel
    // ══════════════════════════════════════════════════════════════════════════

    public function test_storno_appears_with_its_stored_sign(): void
    {
        $invoice = $this->makeInvoice(['gross_total' => 12700]);

        app(CurrentCompany::class)->set($this->company->id);
        app(InvoiceService::class)->cancel($invoice, $this->superadmin);
        app(CurrentCompany::class)->clear();

        $lines = $this->exportLines(['type' => 'storno']);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('-12700', $lines[1]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Multi-company izoláció
    // ══════════════════════════════════════════════════════════════════════════

    public function test_other_companys_documents_are_not_exported(): void
    {
        [$companyB, $partnerB, $pmB, $vatB, $seriesB] = $this->makeCompanyFixtures('Export Isol Kft.');
        $this->makeInvoiceFor($companyB, $partnerB, $pmB, $vatB, $seriesB, ['invoice_number' => 'ISOLATED-0001']);
        $this->makeInvoice(['gross_total' => 1000]);

        $content = $this->exportContent([]);

        $this->assertStringNotContainsString('ISOLATED-0001', $content);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Jogosultság
    // ══════════════════════════════════════════════════════════════════════════

    public function test_export_without_invoice_or_receipt_view_returns_403(): void
    {
        $this->makeInvoice();
        $user = $this->makeUserWithPermissions([]);

        $this->asUser($user)
            ->getJson('/api/documents/export')
            ->assertForbidden();
    }

    public function test_export_with_invoice_view_only_succeeds_and_excludes_receipts(): void
    {
        $this->makeInvoice();
        $this->makeReceipt();
        $user = $this->makeUserWithPermissions(['invoice.view']);

        $lines = $this->exportLinesAs($user, []);

        $this->assertCount(2, $lines, 'fejléc + 1 számla, nyugta nélkül');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

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

    private function exportContent(array $query): string
    {
        return $this->exportContentAs($this->superadmin, $query);
    }

    private function exportContentAs(User $user, array $query): string
    {
        $response = $this->asUser($user)->get('/api/documents/export?'.http_build_query($query));
        $response->assertOk();

        return $response->streamedContent();
    }

    /** @return string[] */
    private function exportLines(array $query): array
    {
        return $this->exportLinesAs($this->superadmin, $query);
    }

    /** @return string[] */
    private function exportLinesAs(User $user, array $query): array
    {
        $content = $this->exportContentAs($user, $query);

        return array_values(array_filter(explode("\n", trim(ltrim($content, "\xEF\xBB\xBF")))));
    }

    private function asUser(User $user): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }

    private function makeUserWithPermissions(array $permissionKeys): User
    {
        self::$seq++;
        $user = User::create([
            'name' => 'Export User '.self::$seq,
            'email' => 'doc.export.user.'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => false,
        ]);
        $user->companies()->attach($this->company->id, ['is_default' => true]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['module' => explode('.', $key)[0], 'description' => $key, 'is_sensitive' => false]
            );
            $user->permissionOverrides()->create([
                'company_id' => $this->company->id,
                'permission_id' => $permission->id,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return $user;
    }

    /** @return array{0: Company, 1: Partner, 2: PaymentMethod, 3: VatRate, 4: DocumentSeries} */
    private function makeCompanyFixtures(string $namePrefix): array
    {
        self::$seq++;

        $company = Company::create([
            'name' => $namePrefix.' '.self::$seq,
            'tax_number' => '7777777'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'Export u. '.self::$seq.'.',
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($company->id);

        $partner = Partner::create([
            'type' => 'customer', 'name' => 'Export Partner '.self::$seq,
            'tax_number' => '12345678-1-42',
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        $paymentMethod = PaymentMethod::create(['code' => 'EXPCASH'.self::$seq, 'name' => 'Készpénz', 'is_active' => true]);
        $vatRate = VatRate::create(['name' => 'ÁFA 27% '.self::$seq, 'rate_percent' => 27.00, 'nav_code' => '27', 'is_active' => true]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'document_type' => DocumentType::Invoice,
            'prefix' => 'EXP'.self::$seq, 'reset_yearly' => true, 'next_number' => 1,
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
            'invoice_number' => sprintf('EXP-%s-%06d', now()->format('Ym'), self::$docSeq),
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
            'receipt_number' => sprintf('EXPNY-%s-%06d', now()->format('Ym'), self::$docSeq),
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
}
