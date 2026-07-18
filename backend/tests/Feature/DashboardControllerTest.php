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
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\VatRate;
use App\Services\InvoiceService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GET /api/dashboard — end-to-end tesztek a DashboardController + DashboardService
 * párosra: gating (jog/modul, ebben a sorrendben), sztornó-kizárás (VALÓDI
 * InvoiceService::cancel()-lel, nem kézzel gyártott storno_of_invoice_id-vel),
 * fizetési állapotok, deviza-bontás, multi-company izoláció.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    private Company $company;
    private Partner $partner;
    private PaymentMethod $paymentMethod;
    private VatRate $vatRate;
    private DocumentSeries $invoiceSeries;

    protected function setUp(): void
    {
        parent::setUp();

        // SendInvoiceToNavJob ne fusson le szinkronban a cancel()/create() hívások alatt.
        Queue::fake();

        [$this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries]
            = $this->makeCompanyFixtures('Dashboard HTTP Kft.');
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Sztornó-kezelés
    // ══════════════════════════════════════════════════════════════════════════

    public function test_cancelled_invoice_disappears_from_every_widget(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $invoice = $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'due_date' => now()->subDays(10)->toDateString(),
        ]);

        app(CurrentCompany::class)->set($this->company->id);
        app(InvoiceService::class)->cancel($invoice, $superadmin);
        app(CurrentCompany::class)->clear();

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertSame([], $data['widgets']['unpaid_invoices']['totals'], 'A sztornózott számla nem számíthat a kifizetetlen összegbe');
        $this->assertSame([], $data['widgets']['overdue_invoices']['totals'], 'A sztornózott számla nem számíthat a lejárt összegbe');
        $this->assertSame([], $data['widgets']['monthly_revenue']['totals'], 'A sztornózott számla nem számíthat a havi árbevételbe');
        $this->assertSame([], $data['widgets']['oldest_unpaid']['items'], 'A sztornózott számla nem jelenhet meg a legrégebbi kifizetetlenek közt');
    }

    public function test_storno_document_itself_does_not_appear_anywhere(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $invoice = $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries);

        app(CurrentCompany::class)->set($this->company->id);
        $storno = app(InvoiceService::class)->cancel($invoice, $superadmin);
        app(CurrentCompany::class)->clear();

        $this->assertSame(InvoiceStatus::Storno, $storno->status);
        $this->assertTrue((float) $storno->gross_total < 0, 'A sztornó összegének negatívnak kell lennie');

        $data = $this->dashboardAs($superadmin, $this->company);

        $ids = array_column($data['widgets']['oldest_unpaid']['items'], 'id');
        $this->assertNotContains($storno->id, $ids, 'A sztornó-bizonylat maga sem jelenhet meg widgetben');
        $this->assertSame([], $data['widgets']['unpaid_invoices']['totals']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Fizetési állapotok
    // ══════════════════════════════════════════════════════════════════════════

    public function test_partial_payment_reduces_amount_by_the_paid_sum(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $invoice = $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'payment_status' => PaymentStatus::Partial,
            'gross_total' => 20000,
        ]);
        Payment::create([
            'company_id' => $this->company->id,
            'payable_type' => Invoice::class,
            'payable_id' => $invoice->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 5000,
            'currency' => 'HUF',
            'paid_at' => now(),
        ]);

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertEquals(15000.0, $data['widgets']['unpaid_invoices']['totals'][0]['amount']);
    }

    public function test_payment_in_a_different_currency_is_not_deducted(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $invoice = $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'payment_status' => PaymentStatus::Partial,
            'currency' => 'HUF',
            'gross_total' => 20000,
        ]);
        // Hibás rögzítés a valóságban (eltérő pénznemű fizetés) — a dashboard nem
        // konvertál árfolyammal, ezért figyelmen kívül kell hagynia.
        Payment::create([
            'company_id' => $this->company->id,
            'payable_type' => Invoice::class,
            'payable_id' => $invoice->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 999,
            'currency' => 'EUR',
            'paid_at' => now(),
        ]);

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertEquals(20000.0, $data['widgets']['unpaid_invoices']['totals'][0]['amount'], 'Az eltérő devizájú fizetés nem vonódhat le');
    }

    public function test_paid_invoice_does_not_appear_in_unpaid_or_overdue(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'payment_status' => PaymentStatus::Paid,
            'due_date' => now()->subDays(5)->toDateString(),
        ]);

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertSame([], $data['widgets']['unpaid_invoices']['totals']);
        $this->assertSame([], $data['widgets']['overdue_invoices']['totals']);
    }

    public function test_draft_invoice_does_not_appear_anywhere(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'status' => InvoiceStatus::Draft,
            'due_date' => now()->subDays(5)->toDateString(),
        ]);

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertSame([], $data['widgets']['unpaid_invoices']['totals']);
        $this->assertSame([], $data['widgets']['overdue_invoices']['totals']);
        $this->assertSame([], $data['widgets']['monthly_revenue']['totals']);
        $this->assertSame([], $data['widgets']['oldest_unpaid']['items']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Deviza
    // ══════════════════════════════════════════════════════════════════════════

    public function test_multiple_currencies_produce_multiple_totals_entries(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'currency' => 'HUF', 'gross_total' => 10000,
        ]);
        $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'currency' => 'EUR', 'gross_total' => 100,
        ]);

        $data = $this->dashboardAs($superadmin, $this->company);

        $totals = collect($data['widgets']['unpaid_invoices']['totals'])->keyBy('currency');
        $this->assertCount(2, $totals);
        $this->assertEquals(10000.0, $totals['HUF']['amount']);
        $this->assertEquals(100.0, $totals['EUR']['amount']);
    }

    public function test_empty_company_returns_empty_totals_not_null(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertSame([], $data['widgets']['unpaid_invoices']['totals']);
        $this->assertIsArray($data['widgets']['unpaid_invoices']['totals']);
        $this->assertSame([], $data['widgets']['monthly_revenue']['totals']);
        $this->assertSame([], $data['widgets']['oldest_unpaid']['items']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // days_overdue előjel
    // ══════════════════════════════════════════════════════════════════════════

    public function test_days_overdue_is_positive_for_an_overdue_invoice(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'due_date' => now()->subDays(7)->toDateString(),
        ]);

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertSame(7, $data['widgets']['oldest_unpaid']['items'][0]['days_overdue']);
    }

    public function test_days_overdue_is_negative_for_a_future_due_date(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertSame(-10, $data['widgets']['oldest_unpaid']['items'][0]['days_overdue']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Gating
    // ══════════════════════════════════════════════════════════════════════════

    public function test_user_without_invoice_view_gets_no_permission_on_all_four_invoice_widgets(): void
    {
        $user = $this->makeUser();
        $this->attachToCompany($user, $this->company);

        $data = $this->dashboardAs($user, $this->company);

        foreach (['unpaid_invoices', 'overdue_invoices', 'monthly_revenue', 'oldest_unpaid'] as $key) {
            $this->assertSame(
                ['available' => false, 'reason' => 'no_permission'],
                $data['widgets'][$key],
                "A(z) {$key} widget KIZÁRÓLAG available+reason kulcsokat tartalmazhat"
            );
        }
    }

    public function test_nav_status_module_disabled_when_nav_module_is_off(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        // nav modul szándékosan nincs regisztrálva/bekapcsolva ehhez a céghez

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertSame(
            ['available' => false, 'reason' => 'module_disabled'],
            $data['widgets']['nav_status']
        );
    }

    public function test_nav_status_no_permission_when_module_on_but_permission_missing(): void
    {
        $user = $this->makeUser();
        $this->attachToCompany($user, $this->company);
        $navModule = Module::create([
            'key' => 'nav', 'name' => 'NAV', 'description' => 'test',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10,
        ]);
        $this->company->enabledModules()->attach($navModule->id, ['enabled' => true]);

        $data = $this->dashboardAs($user, $this->company);

        $this->assertSame(
            ['available' => false, 'reason' => 'no_permission'],
            $data['widgets']['nav_status']
        );
    }

    public function test_nav_status_module_disabled_wins_over_missing_permission(): void
    {
        $user = $this->makeUser();
        $this->attachToCompany($user, $this->company);
        // nav modul KI van kapcsolva ÉS a usernek sincs nav.view_log joga —
        // a modul-ellenőrzésnek kell nyernie, mert az fut előbb.

        $data = $this->dashboardAs($user, $this->company);

        $this->assertSame(
            ['available' => false, 'reason' => 'module_disabled'],
            $data['widgets']['nav_status']
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Multi-company izoláció
    // ══════════════════════════════════════════════════════════════════════════

    public function test_other_companys_invoices_do_not_leak_into_any_widget(): void
    {
        $superadmin = $this->makeSuperadminIn($this->company);
        $navModule = Module::create([
            'key' => 'nav', 'name' => 'NAV', 'description' => 'test',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10,
        ]);
        $this->company->enabledModules()->attach($navModule->id, ['enabled' => true]);

        $this->makeInvoice($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->invoiceSeries, [
            'currency' => 'HUF', 'gross_total' => 1000, 'due_date' => now()->subDays(3)->toDateString(),
        ]);

        // Másik cég, saját törzsadatokkal, saját (lejárt + NAV-hibás) számlájával.
        [$companyB, $partnerB, $pmB, $vatB, $seriesB] = $this->makeCompanyFixtures('Dashboard Isol Kft.');
        $this->makeInvoice($companyB, $partnerB, $pmB, $vatB, $seriesB, [
            'currency' => 'HUF', 'gross_total' => 999999, 'due_date' => now()->subDays(100)->toDateString(),
        ]);
        $this->makeInvoice($companyB, $partnerB, $pmB, $vatB, $seriesB, [
            'nav_status' => 'error',
        ]);

        $data = $this->dashboardAs($superadmin, $this->company);

        $this->assertEquals(1000.0, $data['widgets']['unpaid_invoices']['totals'][0]['amount'], 'CompanyB összege nem szivároghat be');
        $this->assertEquals(1000.0, $data['widgets']['overdue_invoices']['totals'][0]['amount']);
        $this->assertEquals(1000.0, $data['widgets']['monthly_revenue']['totals'][0]['amount']);
        $this->assertCount(1, $data['widgets']['oldest_unpaid']['items']);
        $this->assertSame(0, $data['widgets']['nav_status']['error_count'], 'CompanyB hibás NAV-számlája nem számíthat companyA-nál');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Auth
    // ══════════════════════════════════════════════════════════════════════════

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * @return array{0: Company, 1: Partner, 2: PaymentMethod, 3: VatRate, 4: DocumentSeries}
     */
    private function makeCompanyFixtures(string $namePrefix): array
    {
        self::$seq++;

        $company = Company::create([
            'name' => $namePrefix.' '.self::$seq,
            'tax_number' => '4444444'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000',
            'city' => 'Budapest',
            'address_line' => 'Dashboard u. '.self::$seq.'.',
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($company->id);

        $partner = Partner::create([
            'type' => 'customer',
            'name' => 'Dashboard Partner '.self::$seq,
            'billing_postal_code' => '1000',
            'billing_city' => 'Budapest',
            'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        $paymentMethod = PaymentMethod::create([
            'code' => 'DASHCASH'.self::$seq,
            'name' => 'Készpénz',
            'is_active' => true,
        ]);

        $vatRate = VatRate::create([
            'name' => 'ÁFA 27% '.self::$seq,
            'rate_percent' => 27.00,
            'nav_code' => '27',
            'is_active' => true,
        ]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'document_type' => DocumentType::Invoice,
            'prefix' => 'DB'.self::$seq,
            'reset_yearly' => true,
            'next_number' => 1,
        ]);

        app(CurrentCompany::class)->clear();

        return [$company, $partner, $paymentMethod, $vatRate, $series];
    }

    private function makeInvoice(
        Company $company,
        Partner $partner,
        PaymentMethod $paymentMethod,
        VatRate $vatRate,
        DocumentSeries $series,
        array $overrides = []
    ): Invoice {
        self::$docSeq++;

        $grossTotal = $overrides['gross_total'] ?? 1270.0;

        $invoice = Invoice::create(array_merge([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => sprintf('DB-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => round($grossTotal / 1.27, 2),
            'vat_total' => round($grossTotal - $grossTotal / 1.27, 2),
            'gross_total' => $grossTotal,
            'gross_total_base_currency' => $grossTotal,
        ], $overrides));

        $invoice->items()->create([
            'description' => 'Teszt tétel',
            'quantity' => 1.0,
            'unit' => 'db',
            'unit_price' => (float) $invoice->net_total,
            'vat_rate_id' => $vatRate->id,
            'net_amount' => (float) $invoice->net_total,
            'vat_amount' => (float) $invoice->vat_total,
            'gross_amount' => (float) $invoice->gross_total,
            'sort_order' => 0,
        ]);

        return $invoice;
    }

    private function makeUser(bool $superadmin = false): User
    {
        self::$seq++;

        return User::create([
            'name' => 'Dashboard User '.self::$seq,
            'email' => 'dashboard.user.'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function makeSuperadminIn(Company $company): User
    {
        $user = $this->makeUser(superadmin: true);
        $this->attachToCompany($user, $company);

        return $user;
    }

    private function attachToCompany(User $user, Company $company): void
    {
        $user->companies()->attach($company->id, ['is_default' => true]);
    }

    private function inCompany(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }

    private function dashboardAs(User $user, Company $company): array
    {
        return $this->inCompany($user, $company)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->json();
    }
}
