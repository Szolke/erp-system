<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
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
 * PDF archiváló tesztek.
 *
 * Tesztelt viselkedések:
 *  1. persistInvoice / persistReceipt a helyes (determinisztikus) elérési útra ír.
 *  2. A letöltő végpont a tárolt fájl tartalmát streameli, ha az létezik.
 *  3. Más cég nem érheti el az A cég bizonylatának PDF-jét (404).
 *  4. Fallback: ha nincs tárolt fájl, a végpont generál + ment + streamel.
 *  5. Meglévő fájlt soha nem ír felül (forInvoice nem hívódik meg).
 *
 * Storage::fake('local') minden tesztben garantálja, hogy nem kerül fájl a valódi lemezre.
 * PdfService::forInvoice / forReceipt stub-olva van, hogy a DomPDF renderelés
 * ne legyen előfeltétele a strukturális teszteknek.
 */
class PdfArchivalTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq    = 0;
    private static int $docSeq = 0;

    private Company        $company;
    private Company        $companyB;
    private User           $user;
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
            'name'                => 'PDF Teszt Kft. '.self::$seq,
            'tax_number'          => '1111111'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'PDF u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $this->companyB = Company::create([
            'name'                => 'Másik Kft. '.self::$seq,
            'tax_number'          => '2222222'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad(self::$seq + 100, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '2000',
            'city'                => 'Debrecen',
            'address_line'        => 'Másik u. 2.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $this->user = User::create([
            'name'          => 'PDF Tesztelő '.self::$seq,
            'email'         => 'pdf.test.'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);

        $this->user->companies()->attach([
            $this->company->id  => ['is_default' => true],
            $this->companyB->id => ['is_default' => false],
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
            'prefix'        => 'SZ',
            'reset_yearly'  => true,
            'next_number'   => 1,
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

    // ─── Test 1a: persistInvoice helyes úton ír fájlt ───────────────────────

    public function test_persist_invoice_writes_pdf_at_deterministic_path(): void
    {
        $invoice = $this->makeInvoice();
        $expected = $this->pdfPath($invoice->invoice_number, $invoice->company_id, $invoice->issue_date);

        $this->withStubForInvoice(function () use ($invoice) {
            app(PdfService::class)->persistInvoice($invoice);
        });

        Storage::disk('local')->assertExists($expected);
    }

    // ─── Test 1b: persistReceipt helyes úton ír fájlt ───────────────────────

    public function test_persist_receipt_writes_pdf_at_deterministic_path(): void
    {
        $receipt  = $this->makeReceipt();
        $expected = $this->pdfPath($receipt->receipt_number, $receipt->company_id, $receipt->issue_date);

        $this->withStubForReceipt(function () use ($receipt) {
            app(PdfService::class)->persistReceipt($receipt);
        });

        Storage::disk('local')->assertExists($expected);
    }

    // ─── Test 2: Végpont a tárolt fájlt streameli, ha létezik ───────────────

    public function test_pdf_endpoint_serves_stored_invoice_content(): void
    {
        $invoice = $this->makeInvoice();
        $path    = $this->pdfPath($invoice->invoice_number, $invoice->company_id, $invoice->issue_date);
        Storage::disk('local')->put($path, '%PDF-stored');

        $response = $this->inCompany($this->user, $this->company)
            ->get("/api/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-stored', $response->streamedContent());
    }

    // ─── Test 3a: Más cég nem érheti el a számla PDF-jét ───────────────────

    public function test_pdf_endpoint_returns_404_for_other_company_invoice(): void
    {
        $invoice = $this->makeInvoice(); // A céghez tartozik

        $response = $this->inCompany($this->user, $this->companyB)
            ->get("/api/invoices/{$invoice->id}/pdf");

        $response->assertNotFound();
    }

    // ─── Test 3b: Más cég nem érheti el a nyugta PDF-jét ───────────────────

    public function test_pdf_endpoint_returns_404_for_other_company_receipt(): void
    {
        $receipt = $this->makeReceipt(); // A céghez tartozik

        $response = $this->inCompany($this->user, $this->companyB)
            ->get("/api/receipts/{$receipt->id}/pdf");

        $response->assertNotFound();
    }

    // ─── Test 4: Fallback: nincs fájl → generál + ment + streamel ───────────

    public function test_pdf_endpoint_fallback_saves_and_streams_when_file_missing(): void
    {
        $invoice = $this->makeInvoice();
        $path    = $this->pdfPath($invoice->invoice_number, $invoice->company_id, $invoice->issue_date);

        // forInvoice stub-olva, hogy ne kelljen valódi DomPDF renderelés
        $this->partialMock(PdfService::class, function (MockInterface $mock) {
            $mock->shouldReceive('forInvoice')
                ->once()
                ->andReturn($this->makePdfStub('%PDF-fallback'));
        });

        Storage::disk('local')->assertMissing($path);

        $response = $this->inCompany($this->user, $this->company)
            ->get("/api/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        Storage::disk('local')->assertExists($path);
        $this->assertSame('%PDF-fallback', $response->streamedContent());
    }

    // ─── Test 5: Meglévő fájlt soha nem ír felül ────────────────────────────

    public function test_persist_invoice_does_not_overwrite_existing_file(): void
    {
        $invoice = $this->makeInvoice();
        $path    = $this->pdfPath($invoice->invoice_number, $invoice->company_id, $invoice->issue_date);
        Storage::disk('local')->put($path, '%PDF-original');

        // forInvoice nem hívódhat meg, ha a fájl már létezik
        $this->partialMock(PdfService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('forInvoice');
        });

        app(PdfService::class)->persistInvoice($invoice);

        $this->assertSame('%PDF-original', Storage::disk('local')->get($path));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Determinisztikus tárolási útvonal — tükrözi PdfService::storagePath() logikáját. */
    private function pdfPath(string $number, int $companyId, mixed $issueDate): string
    {
        $d = $issueDate instanceof \DateTimeInterface ? $issueDate : \Carbon\Carbon::parse($issueDate);
        return sprintf('documents/%d/%s/%s.pdf', $companyId, $d->format('Y/m/d'), $number);
    }

    /**
     * Lefuttatja a callback-et úgy, hogy a PdfService::forInvoice() egy PDF stub-ot ad vissza.
     * Így tesztelhető a persist logika anélkül, hogy DomPDF renderelés történne.
     */
    private function withStubForInvoice(callable $callback): void
    {
        $stub = $this->makePdfStub('%PDF-stub');
        $this->partialMock(PdfService::class, fn (MockInterface $m) =>
            $m->shouldReceive('forInvoice')->andReturn($stub)
        );
        $callback();
    }

    private function withStubForReceipt(callable $callback): void
    {
        $stub = $this->makePdfStub('%PDF-stub');
        $this->partialMock(PdfService::class, fn (MockInterface $m) =>
            $m->shouldReceive('forReceipt')->andReturn($stub)
        );
        $callback();
    }

    /**
     * DomPDF stub: Mockery mock ami kielégíti a \Barryvdh\DomPDF\PDF return type-ot.
     * Csak output()-ot implementál — Content-Type-tól független.
     */
    private function makePdfStub(string $content): \Barryvdh\DomPDF\PDF
    {
        $mock = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $mock->shouldReceive('output')->andReturn($content);
        return $mock;
    }

    /**
     * Hitelesített HTTP kérés a megadott user + company kontextusában.
     * Origin: http://localhost szükséges a Sanctum stateful middleware-hez.
     */
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
            'invoice_number'            => sprintf('SZ-%s-%06d', now()->format('Ym'), self::$docSeq),
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
            'receipt_number'     => sprintf('NY-%s-%06d', now()->format('Ym'), self::$docSeq),
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
