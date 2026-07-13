<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\NavEnvironment;
use App\Enums\NavStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendInvoiceToNavJob;
use App\Models\Company;
use App\Models\CompanyNavCredential;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\VatRate;
use App\Modules\ModuleResolver;
use App\Services\Nav\NavReporterFactory;
use App\Services\Nav\NavXmlBuilder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use NavOnlineInvoice\Reporter;
use Tests\TestCase;

/**
 * Tests for SendInvoiceToNavJob::handle() — the job's INTERNAL logic.
 *
 * These tests run handle() directly (not via Queue::fake()) so that the
 * actual guard, credential lookup, and factory wiring are exercised.
 *
 * External NAV API calls are NEVER made: NavReporterFactory and
 * NavXmlBuilder are mocked at the container level for the send-path test.
 */
class SendInvoiceToNavJobTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private Invoice  $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        self::$seq++;

        $this->company = Company::create([
            'name'                => 'NAV Teszt Kft. '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'NAV u. '.self::$seq.'.',
            'base_currency'       => 'HUF',
            'nav_environment'     => NavEnvironment::Test,
        ]);

        app(CurrentCompany::class)->set($this->company->id);

        $vatRate = VatRate::create([
            'name'         => 'ÁFA 27%',
            'rate_percent' => 27.00,
            'nav_code'     => '27',
            'is_active'    => true,
        ]);

        $paymentMethod = PaymentMethod::create([
            'code'      => 'CASH'.self::$seq,
            'name'      => 'Készpénz',
            'is_active' => true,
        ]);

        $partner = Partner::create([
            'type'                 => 'customer',
            'name'                 => 'Teszt Vevő',
            'billing_postal_code'  => '1000',
            'billing_city'         => 'Budapest',
            'billing_address_line' => 'Fő u. 1.',
            'default_currency'     => 'HUF',
        ]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id'    => $this->company->id,
            'document_type' => DocumentType::Invoice,
            'prefix'        => 'SZ',
            'reset_yearly'  => true,
            'next_number'   => 1,
        ]);

        $user = User::create([
            'name'     => 'Teszt User',
            'email'    => 'navjob.user.'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->invoice = Invoice::create([
            'company_id'                => $this->company->id,
            'partner_id'                => $partner->id,
            'document_series_id'        => $series->id,
            'invoice_number'            => sprintf('SZ-%s-%06d', now()->format('Ym'), self::$seq),
            'issue_date'                => now()->toDateString(),
            'fulfillment_date'          => now()->toDateString(),
            'due_date'                  => now()->toDateString(),
            'currency'                  => 'HUF',
            'exchange_rate'             => 1.0,
            'exchange_rate_date'        => now()->toDateString(),
            'payment_method_id'         => $paymentMethod->id,
            'status'                    => InvoiceStatus::Issued,
            'payment_status'            => PaymentStatus::Open,
            'net_total'                 => 10000.00,
            'vat_total'                 => 2700.00,
            'gross_total'               => 12700.00,
            'gross_total_base_currency' => 12700.00,
            'created_by'                => $user->id,
        ]);

        $this->invoice->items()->create([
            'description'  => 'Teszt tétel',
            'quantity'     => 1.0,
            'unit'         => 'db',
            'unit_price'   => 10000.0,
            'vat_rate_id'  => $vatRate->id,
            'net_amount'   => 10000.0,
            'vat_amount'   => 2700.0,
            'gross_amount' => 12700.0,
            'sort_order'   => 0,
        ]);
    }

    // ─── Test 1: NAV modul OFF → NotApplicable + log ─────────────────────────

    public function test_job_skips_and_logs_when_nav_module_is_disabled(): void
    {
        // No nav Module row → ModuleResolver returns only core keys → NAV is off.
        Log::spy();

        $job = new SendInvoiceToNavJob($this->invoice->id);
        app()->call([$job, 'handle']);

        $this->invoice->refresh();
        $this->assertSame(NavStatus::NotApplicable, $this->invoice->nav_status);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'nav module disabled'));
    }

    // ─── Test 2: NAV modul ON, nincs credential → NotApplicable ──────────────

    public function test_job_marks_not_applicable_when_no_active_credential(): void
    {
        $navModule = $this->makeNavModule();
        $this->enableNavModule($this->company, $navModule);
        // No CompanyNavCredential created intentionally.

        $job = new SendInvoiceToNavJob($this->invoice->id);
        app()->call([$job, 'handle']);

        $this->invoice->refresh();
        $this->assertSame(NavStatus::NotApplicable, $this->invoice->nav_status);
    }

    // ─── Test 3: NAV modul ON + credential → küld, nav_status = Sent ─────────

    public function test_job_marks_sent_when_nav_is_enabled_and_credential_exists(): void
    {
        $navModule = $this->makeNavModule();
        $this->enableNavModule($this->company, $navModule);
        $this->makeNavCredential($this->company);

        // Mock NavXmlBuilder so no real XML is built from invoice relations.
        $xmlElement = new \SimpleXMLElement('<root/>');
        $this->mock(NavXmlBuilder::class)
            ->shouldReceive('build')
            ->once()
            ->andReturn($xmlElement);

        // Mock NavReporterFactory so no real HTTP call goes out.
        $mockReporter = \Mockery::mock(Reporter::class);
        $mockReporter->shouldReceive('manageInvoice')
            ->once()
            ->with($xmlElement, 'CREATE')
            ->andReturn('TEST-TRX-001');

        $this->mock(NavReporterFactory::class)
            ->shouldReceive('make')
            ->once()
            ->andReturn($mockReporter);

        $job = new SendInvoiceToNavJob($this->invoice->id);
        app()->call([$job, 'handle']);

        $this->invoice->refresh();
        $this->assertSame(NavStatus::Sent, $this->invoice->nav_status);
        $this->assertSame('TEST-TRX-001', $this->invoice->nav_transaction_id);
        $this->assertNotNull($this->invoice->nav_sent_at);
    }

    // ─── Test 4: multi-tenant — a job az invoice company_id-jét használja ────
    //
    // Ha a job véletlenül a CurrentCompany singletonból olvasna (ami HTTP-kéréshez
    // kötött és queue-kontextusban nem érvényes), az alábbi teszt elbukna:
    // CurrentCompany = companyA (NAV ON), invoice = companyB (NAV OFF) →
    // a job companyB modul-állapotát nézi → NotApplicable.

    public function test_job_uses_invoice_company_id_not_current_company_singleton(): void
    {
        // companyA: NAV modul BE → CurrentCompany singleton erre mutat
        $companyA = $this->company; // setUp()-ból
        $navModule = $this->makeNavModule();
        $this->enableNavModule($companyA, $navModule);
        app(CurrentCompany::class)->set($companyA->id); // singleton = companyA (NAV ON)

        // companyB: NAV modul KI (a nav module nincs hozzárendelve)
        self::$seq++;
        $companyB = Company::create([
            'name'                => 'NAV Off Kft. '.self::$seq,
            'tax_number'          => '9999999'.self::$seq.'-2-41',
            'registration_number' => '02-09-'.str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '2000',
            'city'                => 'Szentendre',
            'address_line'        => 'Off u. '.self::$seq.'.',
            'base_currency'       => 'HUF',
            'nav_environment'     => NavEnvironment::Test,
        ]);

        // Az invoice companyB-hez tartozik (NAV OFF cég).
        $this->invoice->update(['company_id' => $companyB->id]);

        $job = new SendInvoiceToNavJob($this->invoice->id);
        app()->call([$job, 'handle']);

        $this->invoice->refresh();
        $this->assertSame(
            NavStatus::NotApplicable,
            $this->invoice->nav_status,
            'A job companyB modul-állapotát kell hogy vizsgálja (NAV OFF), nem a CurrentCompany-ét (NAV ON)'
        );
    }

    // ─── Test 5: NAV modul ON + credential INAKTÍV → NotApplicable + warning ──
    //
    // Különbség Test 2-höz képest: a credential SOR létezik, de is_active=false.
    // A job where('is_active', true) szűrője kizárja → ugyanúgy null → NotApplicable.
    // A modul BE van kapcsolva, tehát ez nem szándékos kihagyás → Log::warning szükséges.

    public function test_job_logs_warning_when_nav_module_is_on_but_credential_is_inactive(): void
    {
        $navModule = $this->makeNavModule();
        $this->enableNavModule($this->company, $navModule);

        // Credential létezik, de le van kapcsolva.
        CompanyNavCredential::create([
            'company_id'       => $this->company->id,
            'environment'      => NavEnvironment::Test,
            'nav_tax_number'   => $this->company->tax_number,
            'nav_login'        => 'demo-test-login',
            'nav_password'     => 'demo-test-password',
            'nav_signing_key'  => 'demo-test-signing-key',
            'nav_exchange_key' => 'demo-test-exchange-key',
            'is_active'        => false,
        ]);

        Log::spy();

        $job = new SendInvoiceToNavJob($this->invoice->id);
        app()->call([$job, 'handle']);

        $this->invoice->refresh();
        $this->assertSame(NavStatus::NotApplicable, $this->invoice->nav_status);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'no active credential for environment'));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeNavModule(): Module
    {
        return Module::create([
            'key'          => 'nav',
            'name'         => 'NAV Online Számla',
            'description'  => 'Test nav module',
            'version'      => '1.0.0',
            'is_core'      => false,
            'is_available' => true,
            'sort_order'   => 10,
        ]);
    }

    private function enableNavModule(Company $company, Module $module): void
    {
        $company->enabledModules()->attach($module->id, ['enabled' => true]);
    }

    private function makeNavCredential(Company $company): CompanyNavCredential
    {
        return CompanyNavCredential::create([
            'company_id'       => $company->id,
            'environment'      => NavEnvironment::Test,
            'nav_tax_number'   => $company->tax_number,
            'nav_login'        => 'demo-test-login',
            'nav_password'     => 'demo-test-password',
            'nav_signing_key'  => 'demo-test-signing-key',
            'nav_exchange_key' => 'demo-test-exchange-key',
            'is_active'        => true,
        ]);
    }
}
