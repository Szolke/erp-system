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
use App\Services\InvoiceService;
use App\Services\ReceiptService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Storno chain tests for InvoiceService::cancel() and ReceiptService::cancel().
 *
 * Cancel rules (InvoiceService / ReceiptService):
 *  1. A storno document itself cannot be cancelled.
 *  2. A document that already has a storno cannot be cancelled again.
 *  3. The storno is assigned a number from the correct storno series (SZSZT / NYSZT),
 *     with gapless sequential numbering within that series.
 *  4. The storno references the original via storno_of_invoice_id / storno_of_receipt_id;
 *     the original's status is NOT changed by cancel().
 *
 * All catalog data (VatRate, PaymentMethod, DocumentSeries) is created explicitly
 * in setUp(); no demo seeders run automatically.
 * Queue::fake() suppresses SendInvoiceToNavJob so no NAV API calls are made.
 */
class StornoChainTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    private Company $company;
    private User $user;
    private VatRate $vatRate;
    private PaymentMethod $paymentMethod;
    private Partner $partner;
    private DocumentSeries $invoiceSeries;
    private DocumentSeries $receiptSeries;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent SendInvoiceToNavJob from executing synchronously (QUEUE_CONNECTION=sync
        // in phpunit.xml). The job would early-exit anyway (NAV_ENABLED defaults to false),
        // but faking is cleaner and avoids the cache/settings lookup entirely.
        Queue::fake();

        self::$seq++;

        $this->company = Company::create([
            'name'                => 'Storno Teszt Kft. '.self::$seq,
            'tax_number'          => '9876543'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Sztornó u. '.self::$seq.'.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $this->user = User::create([
            'name'     => 'Sztornó Aktor',
            'email'    => 'storno.actor.'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
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

        // Set CurrentCompany before creating BelongsToCompany models so the
        // global scope and auto-fill work correctly.
        app(CurrentCompany::class)->set($this->company->id);

        $this->partner = Partner::create([
            'type'                 => 'customer',
            'name'                 => 'Teszt Vevő Bt.',
            'billing_postal_code'  => '1000',
            'billing_city'         => 'Budapest',
            'billing_address_line' => 'Fő u. 1.',
            'default_currency'     => 'HUF',
        ]);

        // Pre-create the original document series so makeInvoice() / makeReceipt()
        // can satisfy the FK without touching InvoiceNumberGenerator.
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

    // ─── Test 1: SZSZT sorozat, hézagmentes sorszámozás ─────────────────────

    public function test_invoice_cancel_uses_szszt_series_with_sequential_numbers(): void
    {
        $invoice1 = $this->makeInvoice();
        $invoice2 = $this->makeInvoice();

        $service = app(InvoiceService::class);

        $storno1 = $service->cancel($invoice1, $this->user);
        $storno2 = $service->cancel($invoice2, $this->user);

        $ym = now()->format('Ym');

        $this->assertStringStartsWith("SZSZT-{$ym}-", $storno1->invoice_number,
            'Az első sztornó számának SZSZT-YYYYMM- előtaggal kell kezdődnie');
        $this->assertStringEndsWith('-000001', $storno1->invoice_number,
            'Az első sztornó szám utolsó tagja 000001 kell legyen');

        $this->assertStringStartsWith("SZSZT-{$ym}-", $storno2->invoice_number,
            'A második sztornó szám is SZSZT-YYYYMM- előtagot kap');
        $this->assertStringEndsWith('-000002', $storno2->invoice_number,
            'A második sztornó szám hézagmentesen következik (000002)');
    }

    // ─── Test 2: NYSZT sorozat nyugta sztornóhoz ─────────────────────────────

    public function test_receipt_cancel_uses_nyszt_series(): void
    {
        $receipt = $this->makeReceipt();

        $service = app(ReceiptService::class);
        $storno = $service->cancel($receipt, $this->user);

        $ym = now()->format('Ym');

        $this->assertStringStartsWith("NYSZT-{$ym}-", $storno->receipt_number,
            'A nyugta sztornó számnak NYSZT-YYYYMM- előtaggal kell kezdődnie');
        $this->assertStringEndsWith('-000001', $storno->receipt_number,
            'Az első NYSZT sztornó számmal 000001-re kell végződnie');
        $this->assertSame(ReceiptStatus::Storno, $storno->status,
            'A sztornó nyugta státusza Storno kell legyen');
    }

    // ─── Test 3: lánc konzisztenciája ────────────────────────────────────────

    public function test_storno_chain_references_are_consistent(): void
    {
        $invoice = $this->makeInvoice();
        $service = app(InvoiceService::class);

        $storno = $service->cancel($invoice, $this->user);

        // Sztornó hivatkozik az eredetire
        $this->assertEquals($invoice->id, $storno->storno_of_invoice_id,
            'A sztornó számla storno_of_invoice_id-je az eredeti számla id-je kell legyen');

        // Eredeti megtalálja a sztornóját a reláción át
        $freshOriginal = $invoice->fresh();
        $this->assertEquals(1, $freshOriginal->stornos()->count(),
            'Az eredeti számlának pontosan egy sztornója kell legyen');
        $this->assertEquals($storno->id, $freshOriginal->stornos()->first()->id,
            'A kapcsolaton át megtalált sztornó azonos kell legyen a visszaadott sztornóval');

        // Sztornó státusza helyes
        $this->assertSame(InvoiceStatus::Storno, $storno->status,
            'A sztornó számla státusza Storno kell legyen');

        // Az eredeti számla státusza NEM változik a sztornózás során
        $this->assertSame(InvoiceStatus::Issued, $freshOriginal->status,
            'Az eredeti számla státusza Issued marad — a cancel() nem módosítja');

        // A sztornó tételek összegei az eredeti negáltjai
        $origItem = $invoice->items->first();
        $stornoItem = $storno->items->first();

        $this->assertEquals(
            round(-1 * (float) $origItem->net_amount, 2),
            (float) $stornoItem->net_amount,
            'A sztornó tétel net_amount értéke az eredeti negáltja kell legyen'
        );
        $this->assertEquals(
            round(-1 * (float) $origItem->gross_amount, 2),
            (float) $stornoItem->gross_amount,
            'A sztornó tétel gross_amount értéke az eredeti negáltja kell legyen'
        );
    }

    // ─── Test 4: kiállított számla nem törölhető, csak sztornózható ──────────

    public function test_issued_invoice_has_no_delete_endpoint(): void
    {
        // DELETE /api/invoices/{id} nincs regisztrálva (apiResource->only(['index','store','show'])).
        // A Laravel router az URI-t egyezteti a GET /api/invoices/{invoice} (show) útvonallal,
        // de a metódus nem egyezik → 405 Method Not Allowed, auth middleware futása előtt.
        $this->deleteJson('/api/invoices/1')->assertStatus(405);
    }

    // ─── Test 5: sztornó számla nem sztornózható újra ─────────────────────────

    public function test_storno_invoice_cannot_be_cancelled(): void
    {
        $invoice = $this->makeInvoice();
        $service = app(InvoiceService::class);

        $storno = $service->cancel($invoice, $this->user);

        $this->expectException(ValidationException::class);

        // A sztornó számla status=Storno → az első guard dobja a kivételt
        $service->cancel($storno, $this->user);
    }

    // ─── Test 6: már sztornózott számla nem sztornózható újra ────────────────

    public function test_already_cancelled_invoice_cannot_be_cancelled_again(): void
    {
        $invoice = $this->makeInvoice();
        $service = app(InvoiceService::class);

        $service->cancel($invoice, $this->user);

        $this->expectException(ValidationException::class);

        // Az eredeti státusza Issued marad, de stornos()->exists() = true → második guard
        $service->cancel($invoice->fresh(), $this->user);
    }

    // ─── Test 7: dupla számla-sztornó kísérlet elutasítva ────────────────────

    public function test_double_invoice_cancel_is_rejected_and_creates_only_one_storno(): void
    {
        $invoice = $this->makeInvoice();
        $service = app(InvoiceService::class);

        $service->cancel($invoice, $this->user);

        $exceptionThrown = false;
        try {
            $service->cancel($invoice->fresh(), $this->user);
        } catch (ValidationException $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown,
            'A második sztornó-kísérletnek ValidationException-t kell dobnia');
        $this->assertSame(1, $invoice->fresh()->stornos()->count(),
            'Az eredeti számlához pontosan egy sztornó létezhet');
    }

    // ─── Test 8: dupla nyugta-sztornó kísérlet elutasítva ────────────────────

    public function test_double_receipt_cancel_is_rejected_and_creates_only_one_storno(): void
    {
        $receipt = $this->makeReceipt();
        $service = app(ReceiptService::class);

        $service->cancel($receipt, $this->user);

        $exceptionThrown = false;
        try {
            $service->cancel($receipt->fresh(), $this->user);
        } catch (ValidationException $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown,
            'A második sztornó-kísérletnek ValidationException-t kell dobnia');
        $this->assertSame(1, $receipt->fresh()->stornos()->count(),
            'Az eredeti nyugtához pontosan egy sztornó létezhet');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Creates an Invoice row directly (no InvoiceService::create) to avoid
     * dispatching SendInvoiceToNavJob for the original documents too.
     */
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

    /**
     * Creates a Receipt row directly (no ReceiptService::create).
     */
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
