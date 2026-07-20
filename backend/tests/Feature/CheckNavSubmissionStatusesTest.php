<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\NavEnvironment;
use App\Enums\NavStatus;
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
 * Tests for the nav:check-submission-status command — the scheduled GUARANTEE
 * half of the NAV verdict check (docs/nav-logging-audit.md phase 2). Runs
 * app()->call() is not used here (Artisan commands are exercised via
 * $this->artisan()), but the same "no real NAV call" mocking discipline as
 * NavTransactionStatusCheckerTest applies throughout.
 */
class CheckNavSubmissionStatusesTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    /**
     * @return array{0: Company, 1: Invoice, 2: CompanyNavCredential}
     */
    private function makeCompanyWithPendingInvoice(string $transactionId, \DateTimeInterface|string $sentAt): array
    {
        self::$seq++;
        $n = self::$seq;

        $company = Company::create([
            'name' => 'NAV Sweep Kft. '.$n,
            'tax_number' => '4234567'.$n.'-2-41',
            'registration_number' => '01-09-'.str_pad($n, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000',
            'city' => 'Budapest',
            'address_line' => 'Sweep u. '.$n.'.',
            'base_currency' => 'HUF',
            'nav_environment' => NavEnvironment::Test,
        ]);

        // Fixture setup only — Partner::create() below relies on BelongsToCompany's
        // auto-fill from CurrentCompany. The command under test never reads this
        // singleton itself (that is precisely what it must not do — see the
        // command's own docblock).
        app(CurrentCompany::class)->set($company->id);

        $credential = CompanyNavCredential::create([
            'company_id' => $company->id,
            'environment' => NavEnvironment::Test,
            'nav_tax_number' => $company->tax_number,
            'nav_login' => 'demo-test-login',
            'nav_password' => 'demo-test-password',
            'nav_signing_key' => 'demo-test-signing-key',
            'nav_exchange_key' => 'demo-test-exchange-key',
            'is_active' => true,
        ]);

        $vatRate = VatRate::firstOrCreate(
            ['nav_code' => '27'],
            ['name' => 'ÁFA 27%', 'rate_percent' => 27.00, 'is_active' => true]
        );

        $paymentMethod = PaymentMethod::create([
            'code' => 'CASH'.$n,
            'name' => 'Készpénz',
            'is_active' => true,
        ]);

        $partner = Partner::create([
            'type' => 'customer',
            'name' => 'Teszt Vevő '.$n,
            'billing_postal_code' => '1000',
            'billing_city' => 'Budapest',
            'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'document_type' => DocumentType::Invoice,
            'prefix' => 'SZ',
            'reset_yearly' => true,
            'next_number' => 1,
        ]);

        $user = User::create([
            'name' => 'Teszt User',
            'email' => 'navsweep.user.'.$n.'@example.com',
            'password' => bcrypt('password'),
        ]);

        $invoice = Invoice::create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => sprintf('SZ-%s-%06d', now()->format('Ym'), $n),
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
            'nav_transaction_id' => $transactionId,
            'nav_sent_at' => $sentAt,
        ]);

        return [$company, $invoice, $credential];
    }

    private function statusResponseXml(string $invoiceStatus): SimpleXMLElement
    {
        $xml = new SimpleXMLElement('<QueryTransactionStatusResponse/>');
        $xml->addChild('processingResults')->addChild('processingResult')->addChild('invoiceStatus', $invoiceStatus);

        return $xml;
    }

    // ─── Test: több cég függő beküldése egy futásban, cégenként a SAJÁT
    //     hitelesítőjével, helyes eredménnyel ──────────────────────────────────

    public function test_processes_multiple_companies_with_correct_credential_each(): void
    {
        [$companyA, $invoiceA, $credentialA] = $this->makeCompanyWithPendingInvoice('TRX-A', now());
        [$companyB, $invoiceB, $credentialB] = $this->makeCompanyWithPendingInvoice('TRX-B', now());

        $reporterA = \Mockery::mock(Reporter::class);
        $reporterA->shouldReceive('queryTransactionStatus')->once()->with('TRX-A')
            ->andReturn($this->statusResponseXml('DONE'));

        $reporterB = \Mockery::mock(Reporter::class);
        $reporterB->shouldReceive('queryTransactionStatus')->once()->with('TRX-B')
            ->andReturn($this->statusResponseXml('ABORTED'));

        $reporterFactory = $this->mock(NavReporterFactory::class);
        $reporterFactory->shouldReceive('make')->once()
            ->with(\Mockery::on(fn ($c) => $c->id === $credentialA->id))->andReturn($reporterA);
        $reporterFactory->shouldReceive('make')->once()
            ->with(\Mockery::on(fn ($c) => $c->id === $credentialB->id))->andReturn($reporterB);

        // A parancs sosem futhat valódi HTTP-kérésen kívül CurrentCompany-ra
        // támaszkodva — ez a teszt-fixture-ök felállítása utáni maradék állapot,
        // nem a parancs valós futási környezete (queue/scheduler: mindig null).
        app(CurrentCompany::class)->clear();

        $this->artisan('nav:check-submission-status')->assertExitCode(0);

        $invoiceA->refresh();
        $invoiceB->refresh();
        $this->assertSame(NavStatus::Confirmed, $invoiceA->nav_status);
        $this->assertSame(NavStatus::Rejected, $invoiceB->nav_status);
    }

    // ─── Test: 24 óránál régebbi függő beküldés → beavatkozást igényel,
    //     NAV-hívás nélkül; egy friss társ ugyanabban a cégben rendesen lefut ──

    public function test_abandons_submission_older_than_24_hours_without_calling_nav(): void
    {
        [$company, $oldInvoice, $credential] = $this->makeCompanyWithPendingInvoice('TRX-OLD', now()->subDay()->subMinute());

        // Ugyanabban a cégben egy friss, pollozható beküldés is fusson helyesen.
        $freshInvoice = Invoice::create([
            'company_id' => $company->id,
            'partner_id' => $oldInvoice->partner_id,
            'document_series_id' => $oldInvoice->document_series_id,
            'invoice_number' => sprintf('SZ-%s-%06d', now()->format('Ym'), self::$seq + 900),
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $oldInvoice->payment_method_id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => 10000.00,
            'vat_total' => 2700.00,
            'gross_total' => 12700.00,
            'gross_total_base_currency' => 12700.00,
            'created_by' => $oldInvoice->created_by,
            'nav_status' => NavStatus::Sent,
            'nav_transaction_id' => 'TRX-FRESH',
            'nav_sent_at' => now(),
        ]);

        $reporter = \Mockery::mock(Reporter::class);
        $reporter->shouldReceive('queryTransactionStatus')
            ->once()
            ->with('TRX-FRESH') // SOSEM 'TRX-OLD'-dal — a lejárt sorra nem megy ki hívás
            ->andReturn($this->statusResponseXml('DONE'));

        $this->mock(NavReporterFactory::class)
            ->shouldReceive('make')
            ->once()
            ->andReturn($reporter);

        app(CurrentCompany::class)->clear();
        $this->artisan('nav:check-submission-status')->assertExitCode(0);

        $oldInvoice->refresh();
        $freshInvoice->refresh();
        $this->assertSame(NavStatus::NeedsAttention, $oldInvoice->nav_status);
        $this->assertSame(NavStatus::Confirmed, $freshInvoice->nav_status);

        // A lejárt sorra nem történt tényleges NAV-hívás — nincs is naplózandó kísérlet.
        $this->assertSame(
            0,
            NavSubmissionLog::where('invoice_id', $oldInvoice->id)->where('operation', 'queryTransactionStatus')->count()
        );
    }

    // ─── Test: egy cég hibája nem akasztja meg a többi cég feldolgozását ─────

    public function test_one_companys_failure_does_not_abort_processing_of_others(): void
    {
        [$companyBroken, $invoiceBroken] = $this->makeCompanyWithPendingInvoice('TRX-BROKEN', now());
        [$companyOk, $invoiceOk] = $this->makeCompanyWithPendingInvoice('TRX-OK', now());

        $mockChecker = $this->mock(NavTransactionStatusChecker::class);
        $mockChecker->shouldReceive('check')
            ->once()
            ->with(\Mockery::on(fn ($i) => $i->id === $invoiceBroken->id), \Mockery::any(), 'TRX-BROKEN')
            ->andThrow(new \RuntimeException('simulated catastrophic failure'));
        $mockChecker->shouldReceive('check')
            ->once()
            ->with(\Mockery::on(fn ($i) => $i->id === $invoiceOk->id), \Mockery::any(), 'TRX-OK')
            ->andReturnNull();

        app(CurrentCompany::class)->clear();

        // A broken cég feldolgozása kivételt dob — ennek ellenére a parancs 0-val
        // tér vissza, és az ok cég sorát is meg kellett hívnia (l. fenti mock-elvárás).
        $this->artisan('nav:check-submission-status')->assertExitCode(0);
    }

    // ─── Test: --invoice= egyetlen számla manuális ellenőrzése, a 24 órás
    //     határidőt megkerülve ────────────────────────────────────────────────

    public function test_invoice_option_checks_a_single_invoice_bypassing_24h_cutoff(): void
    {
        [$company, $invoice, $credential] = $this->makeCompanyWithPendingInvoice('TRX-MANUAL', now()->subDays(3));

        $reporter = \Mockery::mock(Reporter::class);
        $reporter->shouldReceive('queryTransactionStatus')
            ->once()
            ->with('TRX-MANUAL')
            ->andReturn($this->statusResponseXml('DONE'));

        $this->mock(NavReporterFactory::class)
            ->shouldReceive('make')
            ->once()
            ->andReturn($reporter);

        app(CurrentCompany::class)->clear();
        $this->artisan('nav:check-submission-status', ['--invoice' => $invoice->id])
            ->assertExitCode(0);

        $invoice->refresh();
        $this->assertSame(
            NavStatus::Confirmed,
            $invoice->nav_status,
            'A --invoice= manuális ellenőrzésnek a 3 napos kort figyelmen kívül hagyva ténylegesen le kellett futtatnia a NAV-lekérdezést.'
        );
    }

    public function test_invoice_option_reports_error_for_unknown_invoice(): void
    {
        $this->artisan('nav:check-submission-status', ['--invoice' => 999999])
            ->assertExitCode(1);
    }
}
