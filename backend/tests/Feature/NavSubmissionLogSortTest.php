<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\NavStatus;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\NavSubmissionLog;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/nav-submissions — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * Az `invoice`/`partner`/`status` a joinolt invoices/partners táblákra
 * rendez — ehhez az index() lekérdezése MINDIG joinolja mindkettőt (l.
 * NavSubmissionLogController SORTABLE_COLUMNS). Minden lekérdezés
 * `?status=all`-lal fut, hogy a szűrés (alapból csak a hibás állapotú
 * számlák) ne zavarja bele a rendezés-specifikus asserteket.
 */
class NavSubmissionLogSortTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->company = $this->makeCompany();
        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);

        $module = Module::create([
            'key' => 'nav', 'name' => 'NAV', 'description' => 'Test module nav',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10,
        ]);
        $this->company->enabledModules()->attach($module->id, ['enabled' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_without_sort_params_the_previous_default_order_is_kept(): void
    {
        $old = $this->makeInvoice('SZ-OLD');
        $this->makeLog($old, now()->subDays(2));
        $new = $this->makeInvoice('SZ-NEW');
        $this->makeLog($new, now());

        $numbers = $this->invoiceNumbersAs(['status' => 'all']);

        $this->assertSame(['SZ-NEW', 'SZ-OLD'], $numbers, 'Alapértelmezés: legutolsó napló-created_at szerint csökkenő');
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $old = $this->makeInvoice('SZ-OLD');
        $this->makeLog($old, now()->subDays(2));
        $new = $this->makeInvoice('SZ-NEW');
        $this->makeLog($new, now());

        $numbers = $this->invoiceNumbersAs(['status' => 'all', 'sort_by' => 'nincs_ilyen', 'sort_dir' => 'asc']);

        $this->assertSame(['SZ-NEW', 'SZ-OLD'], $numbers);
    }

    /** `invoice` a joinolt invoices.invoice_number oszlopra rendez. */
    public function test_sorting_by_invoice_uses_the_joined_invoice_number_column(): void
    {
        $b = $this->makeInvoice('SZ-B');
        $this->makeLog($b);
        $a = $this->makeInvoice('SZ-A');
        $this->makeLog($a);

        $numbers = $this->invoiceNumbersAs(['status' => 'all', 'sort_by' => 'invoice', 'sort_dir' => 'asc']);

        $this->assertSame(['SZ-A', 'SZ-B'], $numbers);
    }

    /** `partner` a joinolt (invoices ->) partners.name oszlopra rendez. */
    public function test_sorting_by_partner_uses_the_joined_partner_name_column(): void
    {
        $withZ = $this->makeInvoice('SZ-1', partnerName: 'Zeta Partner');
        $this->makeLog($withZ);
        $withA = $this->makeInvoice('SZ-2', partnerName: 'Alfa Partner');
        $this->makeLog($withA);

        $data = $this->requestAs(['status' => 'all', 'sort_by' => 'partner', 'sort_dir' => 'asc'])['data'];
        $names = array_map(fn ($r) => $r['invoice']['partner_name'], $data);

        $this->assertSame(['Alfa Partner', 'Zeta Partner'], $names);
    }

    public function test_sorting_by_status_uses_the_joined_invoice_nav_status_column(): void
    {
        $error = $this->makeInvoice('SZ-ERR', navStatus: NavStatus::Error);
        $this->makeLog($error);
        $sent = $this->makeInvoice('SZ-SENT', navStatus: NavStatus::Sent);
        $this->makeLog($sent);

        $data = $this->requestAs(['status' => 'all', 'sort_by' => 'status', 'sort_dir' => 'asc'])['data'];
        $statuses = array_map(fn ($r) => $r['invoice']['nav_status'], $data);

        // 'error' < 'sent' ábécé szerint — ez a join-oszlop helyes feloldását bizonyítja.
        $this->assertSame(['error', 'sent'], $statuses);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function invoiceNumbersAs(array $query): array
    {
        return array_map(fn ($r) => $r['invoice']['invoice_number'], $this->requestAs($query)['data']);
    }

    private function requestAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/nav-submissions?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeInvoice(string $invoiceNumber, string $partnerName = 'Teszt Partner', NavStatus $navStatus = NavStatus::Error): Invoice
    {
        self::$seq++;

        app(CurrentCompany::class)->set($this->company->id);

        $partner = Partner::create([
            'company_id' => $this->company->id, 'type' => 'customer', 'name' => $partnerName,
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        $paymentMethod = PaymentMethod::firstOrCreate(['code' => 'CASH'], ['name' => 'Készpénz', 'is_active' => true]);

        $series = DocumentSeries::withoutGlobalScope('company')->firstOrCreate(
            ['company_id' => $this->company->id, 'document_type' => DocumentType::Invoice, 'prefix' => 'SZ'],
            ['reset_yearly' => true, 'next_number' => 1]
        );

        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => $invoiceNumber,
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
            'nav_status' => $navStatus,
            'nav_transaction_id' => 'TRX-'.self::$seq,
            'nav_sent_at' => now(),
        ]);

        app(CurrentCompany::class)->clear();

        return $invoice;
    }

    private function makeLog(Invoice $invoice, ?\DateTimeInterface $createdAt = null): NavSubmissionLog
    {
        static $attemptCounter = 0;
        $attemptCounter++;

        $log = NavSubmissionLog::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'attempt_number' => $attemptCounter,
            'operation' => 'manageInvoice',
            'invoice_operation' => 'CREATE',
            'environment' => 'test',
            'transaction_id' => $invoice->nav_transaction_id,
            'request_xml' => null,
            'response_xml' => null,
            'status' => 'success',
        ]);

        if ($createdAt !== null) {
            $log->created_at = $createdAt;
            $log->save();
        }

        return $log;
    }

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::create([
            'name'                => 'Company '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-03',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }

    private function makeUser(bool $superadmin = false): User
    {
        self::$seq++;

        return User::create([
            'name'          => 'User '.self::$seq,
            'email'         => 'user'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function asAdmin(Company $company): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
