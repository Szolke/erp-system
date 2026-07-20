<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\NavEnvironment;
use App\Enums\NavStatus;
use App\Enums\NavSubmissionStatus;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\CompanyNavCredential;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\NavSubmissionLog;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\VatRate;
use App\Services\Nav\NavReporterFactory;
use App\Services\Nav\NavTransactionStatusChecker;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NavOnlineInvoice\Reporter;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * Tests for NavTransactionStatusChecker::check() — the verdict-mapping logic
 * shared by CheckNavSubmissionStatuses (scheduled) and CheckNavTransactionStatusJob
 * (delayed one-shot). The NAV HTTP call is always mocked at NavReporterFactory
 * level; no real request ever leaves these tests.
 */
class NavTransactionStatusCheckerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private Invoice $invoice;
    private CompanyNavCredential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        self::$seq++;

        $this->company = Company::create([
            'name' => 'NAV Státusz Kft. '.self::$seq,
            'tax_number' => '3234567'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000',
            'city' => 'Budapest',
            'address_line' => 'Státusz u. '.self::$seq.'.',
            'base_currency' => 'HUF',
            'nav_environment' => NavEnvironment::Test,
        ]);

        app(CurrentCompany::class)->set($this->company->id);

        $this->credential = CompanyNavCredential::create([
            'company_id' => $this->company->id,
            'environment' => NavEnvironment::Test,
            'nav_tax_number' => $this->company->tax_number,
            'nav_login' => 'demo-test-login',
            'nav_password' => 'demo-test-password',
            'nav_signing_key' => 'demo-test-signing-key',
            'nav_exchange_key' => 'demo-test-exchange-key',
            'is_active' => true,
        ]);

        $vatRate = VatRate::create([
            'name' => 'ÁFA 27%',
            'rate_percent' => 27.00,
            'nav_code' => '27',
            'is_active' => true,
        ]);

        $paymentMethod = PaymentMethod::create([
            'code' => 'CASH'.self::$seq,
            'name' => 'Készpénz',
            'is_active' => true,
        ]);

        $partner = Partner::create([
            'type' => 'customer',
            'name' => 'Teszt Vevő',
            'billing_postal_code' => '1000',
            'billing_city' => 'Budapest',
            'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $this->company->id,
            'document_type' => DocumentType::Invoice,
            'prefix' => 'SZ',
            'reset_yearly' => true,
            'next_number' => 1,
        ]);

        $user = User::create([
            'name' => 'Teszt User',
            'email' => 'navstatus.user.'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
        ]);

        // Simulates a previously successful SendInvoiceToNavJob run — this test
        // targets the CHECK phase, not the send phase.
        $this->invoice = Invoice::create([
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => sprintf('SZ-%s-%06d', now()->format('Ym'), self::$seq),
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => 10000.00,
            'vat_total' => 2700.00,
            'gross_total' => 12700.00,
            'gross_total_base_currency' => 12700.00,
            'created_by' => $user->id,
            'nav_status' => NavStatus::Sent,
            'nav_transaction_id' => 'TEST-TRX-STATUS-001',
            'nav_sent_at' => now(),
        ]);
    }

    private function mockReporterReturning(SimpleXMLElement $responseXml): void
    {
        $mockReporter = \Mockery::mock(Reporter::class);
        $mockReporter->shouldReceive('queryTransactionStatus')
            ->once()
            ->with('TEST-TRX-STATUS-001')
            ->andReturn($responseXml);

        $this->mock(NavReporterFactory::class)
            ->shouldReceive('make')
            ->once()
            ->andReturn($mockReporter);
    }

    private function buildStatusResponseXml(string $invoiceStatus, array $businessMessages = [], array $technicalMessages = []): SimpleXMLElement
    {
        $xml = new SimpleXMLElement('<QueryTransactionStatusResponse/>');
        $processingResults = $xml->addChild('processingResults');
        $processingResult = $processingResults->addChild('processingResult');
        $processingResult->addChild('invoiceStatus', $invoiceStatus);

        foreach ($technicalMessages as $m) {
            $node = $processingResult->addChild('technicalValidationMessages');
            $node->addChild('validationResultCode', $m['severity']);
            if (isset($m['code'])) {
                $node->addChild('validationErrorCode', $m['code']);
            }
            if (isset($m['message'])) {
                $node->addChild('message', $m['message']);
            }
        }

        foreach ($businessMessages as $m) {
            $node = $processingResult->addChild('businessValidationMessages');
            $node->addChild('validationResultCode', $m['severity']);
            if (isset($m['code'])) {
                $node->addChild('validationErrorCode', $m['code']);
            }
            if (isset($m['message'])) {
                $node->addChild('message', $m['message']);
            }
        }

        return $xml;
    }

    // ─── Test 1: DONE, nincs üzenet → elfogadva ──────────────────────────────

    public function test_done_with_no_messages_is_confirmed(): void
    {
        $this->mockReporterReturning($this->buildStatusResponseXml('DONE'));

        app(NavTransactionStatusChecker::class)->check($this->invoice, $this->credential, 'TEST-TRX-STATUS-001');

        $this->invoice->refresh();
        $this->assertSame(NavStatus::Confirmed, $this->invoice->nav_status);

        $log = NavSubmissionLog::where('invoice_id', $this->invoice->id)
            ->where('operation', 'queryTransactionStatus')
            ->firstOrFail();
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertNull($log->invoice_operation);
        $this->assertSame('test', $log->environment);
        $this->assertSame('TEST-TRX-STATUS-001', $log->transaction_id);
        $this->assertNull($log->request_xml);
        $this->assertNotNull($log->response_xml);
        $this->assertSame(NavSubmissionStatus::Success, $log->status);
        $this->assertSame('DONE', $log->processing_result);
        $this->assertSame([], $log->validation_messages);
    }

    // ─── Test 2: DONE + WARN → elfogadva figyelmeztetéssel, NEM hiba ─────────

    public function test_done_with_warning_is_confirmed_with_warnings_not_rejected(): void
    {
        $this->mockReporterReturning($this->buildStatusResponseXml('DONE', businessMessages: [
            ['severity' => 'WARN', 'code' => 'WARN_CODE', 'message' => 'Figyelmeztetés szövege'],
        ]));

        app(NavTransactionStatusChecker::class)->check($this->invoice, $this->credential, 'TEST-TRX-STATUS-001');

        $this->invoice->refresh();
        $this->assertSame(NavStatus::ConfirmedWithWarnings, $this->invoice->nav_status);

        $log = NavSubmissionLog::where('invoice_id', $this->invoice->id)->firstOrFail();
        $this->assertSame('DONE', $log->processing_result);
        $this->assertCount(1, $log->validation_messages);
        $this->assertSame('business', $log->validation_messages[0]['source']);
        $this->assertSame('WARN', $log->validation_messages[0]['severity']);
        $this->assertSame('WARN_CODE', $log->validation_messages[0]['code']);
        $this->assertSame('Figyelmeztetés szövege', $log->validation_messages[0]['message']);
    }

    // ─── Test 3: ABORTED + ERROR → elutasítva, üzenetek strukturáltan mentve ─

    public function test_aborted_with_error_is_rejected_with_structured_messages(): void
    {
        $this->mockReporterReturning($this->buildStatusResponseXml('ABORTED', businessMessages: [
            ['severity' => 'ERROR', 'code' => 'ERROR_CODE', 'message' => 'A számla elutasítva'],
        ]));

        app(NavTransactionStatusChecker::class)->check($this->invoice, $this->credential, 'TEST-TRX-STATUS-001');

        $this->invoice->refresh();
        $this->assertSame(NavStatus::Rejected, $this->invoice->nav_status);

        $log = NavSubmissionLog::where('invoice_id', $this->invoice->id)->firstOrFail();
        $this->assertSame('ABORTED', $log->processing_result);
        $this->assertSame('ERROR', $log->validation_messages[0]['severity']);
        $this->assertSame('ERROR_CODE', $log->validation_messages[0]['code']);
    }

    // ─── Test 4: DONE + ERROR súlyosságú business üzenet is elutasítást ad ───
    //     akkor is, ha az invoiceStatus önmagában nem ABORTED (a szabály "vagy").

    public function test_done_with_error_message_is_rejected(): void
    {
        $this->mockReporterReturning($this->buildStatusResponseXml('DONE', businessMessages: [
            ['severity' => 'ERROR', 'code' => 'ERROR_CODE', 'message' => 'Hiba DONE mellett'],
        ]));

        app(NavTransactionStatusChecker::class)->check($this->invoice, $this->credential, 'TEST-TRX-STATUS-001');

        $this->invoice->refresh();
        $this->assertSame(NavStatus::Rejected, $this->invoice->nav_status);
    }

    // ─── Test 5: még PROCESSING → a számla státusza NEM változik, de a
    //     naplósor létrejön ───────────────────────────────────────────────────

    public function test_still_processing_leaves_invoice_status_unchanged_but_logs(): void
    {
        $this->mockReporterReturning($this->buildStatusResponseXml('PROCESSING'));

        app(NavTransactionStatusChecker::class)->check($this->invoice, $this->credential, 'TEST-TRX-STATUS-001');

        $this->invoice->refresh();
        $this->assertSame(NavStatus::Sent, $this->invoice->nav_status, 'A számla állapota nem változhat, amíg nincs végleges NAV-verdikt.');

        $log = NavSubmissionLog::where('invoice_id', $this->invoice->id)->firstOrFail();
        $this->assertSame('PROCESSING', $log->processing_result);
        $this->assertSame(NavSubmissionStatus::Success, $log->status);
    }

    // ─── Test 5b: SAVED is KÖZTES állapot (nem végleges, mint DONE/ABORTED) —
    //     a számla státusza NEM változik, csak a naplósor jön létre ───────────

    public function test_saved_leaves_invoice_status_unchanged_but_logs(): void
    {
        $this->mockReporterReturning($this->buildStatusResponseXml('SAVED'));

        app(NavTransactionStatusChecker::class)->check($this->invoice, $this->credential, 'TEST-TRX-STATUS-001');

        $this->invoice->refresh();
        $this->assertSame(NavStatus::Sent, $this->invoice->nav_status, 'A SAVED köztes állapot, nem végleges verdikt — a számla állapota nem változhat.');

        $log = NavSubmissionLog::where('invoice_id', $this->invoice->id)->firstOrFail();
        $this->assertSame('SAVED', $log->processing_result);
    }

    // ─── Test 6: a teljes útvonal működik BEÁLLÍTOTT CurrentCompany nélkül is ─

    public function test_check_works_without_current_company_set(): void
    {
        app(CurrentCompany::class)->clear();

        $this->mockReporterReturning($this->buildStatusResponseXml('DONE'));

        app(NavTransactionStatusChecker::class)->check($this->invoice, $this->credential, 'TEST-TRX-STATUS-001');

        $log = NavSubmissionLog::where('invoice_id', $this->invoice->id)->firstOrFail();
        $this->assertSame($this->company->id, $log->company_id);
    }

    // ─── Test 7: attempt_number a queryTransactionStatus sorokon KÜLÖN
    //     számlálón fut, nem keveredik a manageInvoice számlálójával ─────────

    public function test_attempt_number_runs_on_its_own_counter_separate_from_manage_invoice(): void
    {
        // Egy korábbi, sikeres manageInvoice-beküldés naplósora — magas
        // attempt_number-rel, hogy a keveredés kimutatható legyen.
        NavSubmissionLog::create([
            'invoice_id' => $this->invoice->id,
            'company_id' => $this->company->id,
            'attempt_number' => 5,
            'operation' => 'manageInvoice',
            'invoice_operation' => 'CREATE',
            'environment' => 'test',
            'transaction_id' => 'TEST-TRX-STATUS-001',
            'request_xml' => '<InvoiceData/>',
            'response_xml' => 'transactionId: TEST-TRX-STATUS-001',
            'status' => 'success',
        ]);

        $this->mockReporterReturning($this->buildStatusResponseXml('PROCESSING'));

        app(NavTransactionStatusChecker::class)->check($this->invoice, $this->credential, 'TEST-TRX-STATUS-001');

        $queryLog = NavSubmissionLog::where('invoice_id', $this->invoice->id)
            ->where('operation', 'queryTransactionStatus')
            ->firstOrFail();

        $this->assertSame(1, $queryLog->attempt_number, 'Az első queryTransactionStatus kísérletnek 1-gyel kell kezdődnie, nem a manageInvoice 5-ös sorszámát folytatva.');
    }

    // ─── abandon(): 24 óránál régebbi függő beküldés beavatkozást igényel ────

    public function test_abandon_sets_needs_attention_status(): void
    {
        app(NavTransactionStatusChecker::class)->abandon($this->invoice);

        $this->invoice->refresh();
        $this->assertSame(NavStatus::NeedsAttention, $this->invoice->nav_status);
    }
}
