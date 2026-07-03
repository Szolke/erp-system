<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VatRate;
use App\Services\PdfService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * PDF újragenerálás tesztek.
 *
 * Tesztelt viselkedések:
 *  1. Újrageneráláskor a régi PDF archivált (.superseded-*) névré kerül, az új a kanonikus névre.
 *  2. Ha nincs korábbi fájl (pdf_persist_failed / régi bizonylat), egyszerűen generál+ment, hiba nélkül.
 *  3. Audit-esemény keletkezik (invoice.pdf_regenerated / receipt.pdf_regenerated).
 *  4. Más cég nem tud újragenerálni (404).
 *  5. Jogosultság hiányában elutasítja (403).
 *  6. A bizonylat DB-adatai változatlanok maradnak az újragenerálás után.
 *  7. Nyugta újragenerálása szimmetrikusan működik, archiválja a régi fájlt.
 */
class PdfRegenerationTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq    = 0;
    private static int $docSeq = 0;

    private Company        $company;
    private Company        $companyB;
    private User           $user;
    private User           $unprivilegedUser;
    private VatRate        $vatRate;
    private PaymentMethod  $paymentMethod;
    private Partner        $partner;
    private DocumentSeries $invoiceSeries;
    private DocumentSeries $receiptSeries;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        self::$seq++;

        $this->company = Company::create([
            'name'                => 'Regen Teszt Kft. '.self::$seq,
            'tax_number'          => '3333333'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Regen u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $this->companyB = Company::create([
            'name'                => 'Másik Kft. '.self::$seq,
            'tax_number'          => '4444444'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad(self::$seq + 200, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '2000',
            'city'                => 'Debrecen',
            'address_line'        => 'Másik u. 2.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $this->user = User::create([
            'name'          => 'Regen Tesztelő '.self::$seq,
            'email'         => 'regen.test.'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);

        $this->unprivilegedUser = User::create([
            'name'          => 'Alapfelhasználó '.self::$seq,
            'email'         => 'unprivileged.'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => false,
            'is_active'     => true,
        ]);

        $this->user->companies()->attach([
            $this->company->id  => ['is_default' => true],
            $this->companyB->id => ['is_default' => false],
        ]);
        $this->unprivilegedUser->companies()->attach([
            $this->company->id => ['is_default' => true],
        ]);

        $this->vatRate = VatRate::create([
            'name'         => 'ÁFA 27%',
            'rate_percent' => 27.00,
            'nav_code'     => '27',
            'is_active'    => true,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'code'      => 'CASH'.self::$seq,
            'name'      => 'Készpénz',
            'is_active' => true,
        ]);

        app(CurrentCompany::class)->set($this->company->id);

        $this->partner = Partner::create([
            'type'                 => 'customer',
            'name'                 => 'Teszt Vevő Bt.',
            'billing_postal_code'  => '1000',
            'billing_city'         => 'Budapest',
            'billing_address_line' => 'Fő u. 1.',
            'default_currency'     => 'HUF',
        ]);

        $this->invoiceSeries = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id'    => $this->company->id,
            'document_type' => DocumentType::Invoice,
            'prefix'        => 'RG',
            'reset_yearly'  => true,
            'next_number'   => 1,
        ]);

        $this->receiptSeries = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id'    => $this->company->id,
            'document_type' => DocumentType::Receipt,
            'prefix'        => 'RN',
            'reset_yearly'  => true,
            'next_number'   => 1,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ─── Teszt 1: régi PDF archivált, új a kanonikus névre kerül ────────────

    public function test_regenerate_invoice_archives_old_pdf_and_creates_new_one(): void
    {
        $invoice  = $this->makeInvoice();
        $path     = $this->pdfPath($invoice->invoice_number, $invoice->company_id, $invoice->issue_date);

        Storage::disk('local')->put($path, '%PDF-original');

        $this->withPdfStub(function () use ($invoice) {
            $response = $this->inCompany($this->user, $this->company)
                ->postJson("/api/invoices/{$invoice->id}/regenerate-pdf");

            $response->assertOk();
            $this->assertNotNull($response->json('superseded_file'));
        });

        // Kanonikus fájl létezik (az új)
        Storage::disk('local')->assertExists($path);

        // Régi fájl archivált (superseded-* névvel, ugyanabban a mappában)
        $dir   = dirname($path);
        $stem  = pathinfo($path, PATHINFO_FILENAME);
        $files = Storage::disk('local')->files($dir);
        $superseded = array_values(array_filter($files, fn ($f) => str_contains(basename($f), '.superseded-')));
        $this->assertCount(1, $superseded, 'Pontosan egy superseded fájlnak kell lennie');
        $this->assertStringStartsWith($stem . '.superseded-', basename($superseded[0]));

        // Az archivált fájl az eredeti tartalmat hordozza
        $this->assertSame('%PDF-original', Storage::disk('local')->get($superseded[0]));
    }

    // ─── Teszt 2: nincs régi fájl → generál+ment, superseded null, nincs hiba ─

    public function test_regenerate_invoice_without_existing_file_creates_new_pdf_and_returns_null_superseded(): void
    {
        $invoice = $this->makeInvoice();
        $path    = $this->pdfPath($invoice->invoice_number, $invoice->company_id, $invoice->issue_date);

        Storage::disk('local')->assertMissing($path);

        $this->withPdfStub(function () use ($invoice, $path) {
            $response = $this->inCompany($this->user, $this->company)
                ->postJson("/api/invoices/{$invoice->id}/regenerate-pdf");

            $response->assertOk();
            $this->assertNull($response->json('superseded_file'));
        });

        Storage::disk('local')->assertExists($path);
    }

    // ─── Teszt 3: audit-esemény keletkezik ──────────────────────────────────

    public function test_regenerate_invoice_emits_audit_event(): void
    {
        $invoice = $this->makeInvoice();
        $path    = $this->pdfPath($invoice->invoice_number, $invoice->company_id, $invoice->issue_date);
        Storage::disk('local')->put($path, '%PDF-original');

        $this->withPdfStub(function () use ($invoice) {
            $this->inCompany($this->user, $this->company)
                ->postJson("/api/invoices/{$invoice->id}/regenerate-pdf")
                ->assertOk();
        });

        $log = AuditLog::where('action', 'invoice.pdf_regenerated')->latest()->first();
        $this->assertNotNull($log);
        $this->assertSame($invoice->company_id, $log->company_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame($invoice->invoice_number, $log->new_values['document_number']);
        $this->assertNotNull($log->new_values['superseded_file']);
    }

    // ─── Teszt 4: más cég nem tud újragenerálni (404) ───────────────────────

    public function test_regenerate_invoice_cross_company_returns_404(): void
    {
        $invoice = $this->makeInvoice(); // A céghez tartozik

        $response = $this->inCompany($this->user, $this->companyB)
            ->postJson("/api/invoices/{$invoice->id}/regenerate-pdf");

        $response->assertNotFound();
    }

    // ─── Teszt 5: jogosultság hiányában 403 ─────────────────────────────────

    public function test_regenerate_invoice_without_permission_returns_403(): void
    {
        $invoice = $this->makeInvoice();

        $response = $this->inCompany($this->unprivilegedUser, $this->company)
            ->postJson("/api/invoices/{$invoice->id}/regenerate-pdf");

        $response->assertForbidden();
    }

    // ─── Teszt 6: a számla DB-adatai változatlanok maradnak ─────────────────

    public function test_regenerate_invoice_does_not_modify_invoice_db_data(): void
    {
        $invoice = $this->makeInvoice();
        // refresh() ensures getRawOriginal() returns DB-formatted strings (not PHP-typed create() args)
        $invoice->refresh();
        $rawBefore = $invoice->getRawOriginal();

        $this->withPdfStub(function () use ($invoice) {
            $this->inCompany($this->user, $this->company)
                ->postJson("/api/invoices/{$invoice->id}/regenerate-pdf")
                ->assertOk();
        });

        $rawAfter = $invoice->fresh()->getRawOriginal();

        $fields = ['invoice_number', 'status', 'payment_status', 'net_total', 'vat_total',
                   'gross_total', 'issue_date', 'due_date', 'company_id', 'partner_id'];
        foreach ($fields as $field) {
            $this->assertSame($rawBefore[$field], $rawAfter[$field], "A(z) '{$field}' mező megváltozott!");
        }
    }

    // ─── Teszt 7: nyugta újragenerálása is archiválja a régi fájlt ──────────

    public function test_regenerate_receipt_archives_old_pdf_and_creates_new_one(): void
    {
        $receipt  = $this->makeReceipt();
        $path     = $this->pdfPath($receipt->receipt_number, $receipt->company_id, $receipt->issue_date);

        Storage::disk('local')->put($path, '%PDF-receipt-original');

        $this->withReceiptPdfStub(function () use ($receipt) {
            $response = $this->inCompany($this->user, $this->company)
                ->postJson("/api/receipts/{$receipt->id}/regenerate-pdf");

            $response->assertOk();
            $this->assertNotNull($response->json('superseded_file'));
        });

        Storage::disk('local')->assertExists($path);

        $dir        = dirname($path);
        $stem       = pathinfo($path, PATHINFO_FILENAME);
        $files      = Storage::disk('local')->files($dir);
        $superseded = array_values(array_filter($files, fn ($f) => str_contains(basename($f), '.superseded-')));
        $this->assertCount(1, $superseded);
        $this->assertStringStartsWith($stem . '.superseded-', basename($superseded[0]));
        $this->assertSame('%PDF-receipt-original', Storage::disk('local')->get($superseded[0]));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function pdfPath(string $number, int $companyId, mixed $issueDate): string
    {
        $d = $issueDate instanceof \DateTimeInterface ? $issueDate : \Carbon\Carbon::parse($issueDate);
        return sprintf('documents/%d/%s/%s.pdf', $companyId, $d->format('Y/m/d'), $number);
    }

    private function makePdfStub(string $content): \Barryvdh\DomPDF\PDF
    {
        $mock = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $mock->shouldReceive('output')->andReturn($content);
        return $mock;
    }

    private function withPdfStub(callable $callback): void
    {
        $this->partialMock(PdfService::class, fn (MockInterface $m) =>
            $m->shouldReceive('forInvoice')->andReturn($this->makePdfStub('%PDF-regenerated'))
        );
        $callback();
    }

    private function withReceiptPdfStub(callable $callback): void
    {
        $this->partialMock(PdfService::class, fn (MockInterface $m) =>
            $m->shouldReceive('forReceipt')->andReturn($this->makePdfStub('%PDF-receipt-regenerated'))
        );
        $callback();
    }

    private function inCompany(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }

    private function makeInvoice(): Invoice
    {
        self::$docSeq++;

        $invoice = Invoice::create([
            'company_id'                => $this->company->id,
            'partner_id'                => $this->partner->id,
            'document_series_id'        => $this->invoiceSeries->id,
            'invoice_number'            => sprintf('RG-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date'                => now()->toDateString(),
            'fulfillment_date'          => now()->toDateString(),
            'due_date'                  => now()->toDateString(),
            'currency'                  => 'HUF',
            'exchange_rate'             => 1.0,
            'exchange_rate_date'        => now()->toDateString(),
            'payment_method_id'         => $this->paymentMethod->id,
            'status'                    => InvoiceStatus::Issued,
            'payment_status'            => PaymentStatus::Open,
            'net_total'                 => 10000.00,
            'vat_total'                 => 2700.00,
            'gross_total'               => 12700.00,
            'gross_total_base_currency' => 12700.00,
            'created_by'                => $this->user->id,
        ]);

        $invoice->items()->create([
            'description'  => 'Teszt tétel',
            'quantity'     => 1.0,
            'unit'         => 'db',
            'unit_price'   => 10000.0,
            'vat_rate_id'  => $this->vatRate->id,
            'net_amount'   => 10000.0,
            'vat_amount'   => 2700.0,
            'gross_amount' => 12700.0,
            'sort_order'   => 0,
        ]);

        return $invoice;
    }

    private function makeReceipt(): Receipt
    {
        self::$docSeq++;

        $receipt = Receipt::create([
            'company_id'         => $this->company->id,
            'document_series_id' => $this->receiptSeries->id,
            'receipt_number'     => sprintf('RN-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date'         => now()->toDateString(),
            'currency'           => 'HUF',
            'exchange_rate'      => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id'  => $this->paymentMethod->id,
            'status'             => ReceiptStatus::Issued,
            'net_total'          => 10000.00,
            'vat_total'          => 2700.00,
            'gross_total'        => 12700.00,
            'created_by'         => $this->user->id,
        ]);

        $receipt->items()->create([
            'description'  => 'Teszt tétel',
            'quantity'     => 1.0,
            'unit_price'   => 10000.0,
            'vat_rate_id'  => $this->vatRate->id,
            'net_amount'   => 10000.0,
            'vat_amount'   => 2700.0,
            'gross_amount' => 12700.0,
            'sort_order'   => 0,
        ]);

        return $receipt;
    }
}
