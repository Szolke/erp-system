<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\VatRate;
use App\Services\InvoiceService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A GET /api/invoices/{invoice} kétirányú storno-linkelése (InvoiceResource
 * storno_of / storno blokkok) és a GET /api/invoices lista has_storno jelzője.
 *
 * A sztornó a valós InvoiceService::cancel() útvonalon jön létre (A/B/C/D/E/G
 * teszteknél), hogy a teszt a tényleges alkalmazás-viselkedést fedje. Az F
 * teszt kivétel: ott szándékosan "lehetetlen" állapotot állítunk elő közvetlen
 * Eloquent create()-tel, mert épp azt ellenőrizzük, hogy a BelongsToCompany
 * global scope sérült/inkonzisztens adat esetén is véd.
 */
class InvoiceStornoLinkTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    private Company $companyA;
    private Company $companyB;
    private User $user;
    private VatRate $vatRate;
    private PaymentMethod $paymentMethod;
    private Partner $partnerA;
    private Partner $partnerB;
    private DocumentSeries $seriesA;
    private DocumentSeries $seriesB;

    protected function setUp(): void
    {
        parent::setUp();

        // InvoiceService::cancel() SendInvoiceToNavJob-ot dispatchol — a NAV
        // hívást nem akarjuk valósan lefuttatni ezekben a tesztekben.
        Queue::fake();

        self::$seq++;

        $this->companyA = $this->makeCompany('Storno Link A Kft. '.self::$seq, '5551111'.self::$seq.'-2-41');
        $this->companyB = $this->makeCompany('Storno Link B Kft. '.self::$seq, '5552222'.self::$seq.'-2-41');

        $this->user = User::create([
            'name' => 'Storno Link Teszt',
            'email' => 'storno.link.'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => true,
            'is_active' => true,
        ]);
        $this->user->companies()->attach([
            $this->companyA->id => ['is_default' => true],
            $this->companyB->id => ['is_default' => false],
        ]);

        $this->vatRate = VatRate::create([
            'name' => 'ÁFA 27%',
            'rate_percent' => 27.00,
            'nav_code' => '27',
            'is_active' => true,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'code' => 'CASH'.self::$seq,
            'name' => 'Készpénz',
            'is_active' => true,
        ]);

        $this->seriesA = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $this->companyA->id,
            'document_type' => DocumentType::Invoice,
            'prefix' => 'SZ',
            'reset_yearly' => true,
            'next_number' => 1,
        ]);

        $this->seriesB = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $this->companyB->id,
            'document_type' => DocumentType::Invoice,
            'prefix' => 'BB',
            'reset_yearly' => true,
            'next_number' => 1,
        ]);

        app(CurrentCompany::class)->set($this->companyA->id);
        $this->partnerA = Partner::create([
            'type' => 'customer',
            'name' => 'A Teszt Vevő',
            'billing_postal_code' => '1000',
            'billing_city' => 'Budapest',
            'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($this->companyB->id);
        $this->partnerB = Partner::create([
            'type' => 'customer',
            'name' => 'B Teszt Vevő',
            'billing_postal_code' => '2000',
            'billing_city' => 'Debrecen',
            'billing_address_line' => 'Fő u. 2.',
            'default_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->clear();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ─── A: show() — eredeti → sztornó irány ────────────────────────────────

    public function test_show_original_invoice_includes_storno_block(): void
    {
        $invoice = $this->makeInvoice($this->companyA, $this->seriesA, $this->partnerA);
        $storno = app(InvoiceService::class)->cancel($invoice, $this->user);

        $response = $this->inCompany($this->user, $this->companyA)
            ->getJson("/api/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertJsonPath('data.storno.id', $storno->id);
        $response->assertJsonPath('data.storno.invoice_number', $storno->invoice_number);
        $response->assertJsonPath('data.storno.status', 'storno');
    }

    // ─── B: show() — sztornó → eredeti irány ────────────────────────────────

    public function test_show_storno_invoice_includes_storno_of_block(): void
    {
        $invoice = $this->makeInvoice($this->companyA, $this->seriesA, $this->partnerA);
        $storno = app(InvoiceService::class)->cancel($invoice, $this->user);

        $response = $this->inCompany($this->user, $this->companyA)
            ->getJson("/api/invoices/{$storno->id}");

        $response->assertOk();
        $response->assertJsonPath('data.storno_of.id', $invoice->id);
        $response->assertJsonPath('data.storno_of.invoice_number', $invoice->invoice_number);
        // A cancel() nem módosítja az eredeti számla státuszát.
        $response->assertJsonPath('data.storno_of.status', 'issued');
    }

    // ─── C: show() — nincs kapcsolat ────────────────────────────────────────

    public function test_show_invoice_without_storno_relation_returns_null_blocks(): void
    {
        $invoice = $this->makeInvoice($this->companyA, $this->seriesA, $this->partnerA);

        $response = $this->inCompany($this->user, $this->companyA)
            ->getJson("/api/invoices/{$invoice->id}");

        $response->assertOk();
        // A kulcs jelen van (whenLoaded() teljesül, mert a controller mindig
        // eager-loadolja), de az értéke null — ez a frontend szerződése.
        $response->assertJsonPath('data.storno', null);
        $response->assertJsonPath('data.storno_of', null);
    }

    // ─── D: nincs rekurzió / payload-robbanás ───────────────────────────────

    public function test_storno_block_does_not_nest_storno_of(): void
    {
        $invoice = $this->makeInvoice($this->companyA, $this->seriesA, $this->partnerA);
        app(InvoiceService::class)->cancel($invoice, $this->user);

        $response = $this->inCompany($this->user, $this->companyA)
            ->getJson("/api/invoices/{$invoice->id}");

        $response->assertOk();
        $stornoBlock = $response->json('data.storno');
        $this->assertIsArray($stornoBlock);
        $this->assertArrayNotHasKey('storno_of', $stornoBlock);
        $this->assertArrayNotHasKey('storno', $stornoBlock);
        $this->assertArrayNotHasKey('items', $stornoBlock);
        $this->assertSame(['id', 'invoice_number', 'issue_date', 'status'], array_keys($stornoBlock));
    }

    // ─── E: cross-company izoláció — show() ─────────────────────────────────

    public function test_show_other_company_invoice_returns_404(): void
    {
        $invoice = $this->makeInvoice($this->companyA, $this->seriesA, $this->partnerA);

        $response = $this->inCompany($this->user, $this->companyB)
            ->getJson("/api/invoices/{$invoice->id}");

        // assertBelongsToCurrentCompany() -> abort(404), nem 403 (a route model
        // binding a company.context middleware ELŐTT fut, l. EnforcesCompanyScope).
        $response->assertNotFound();
    }

    // ─── F: cross-company izoláció — has_storno a listán (kiemelt eset) ─────

    public function test_list_has_storno_ignores_other_company_storno_reference(): void
    {
        $invoiceA = $this->makeInvoice($this->companyA, $this->seriesA, $this->partnerA);

        // Szándékosan "lehetetlen" állapot: B cégbeli sztornó rekord, ami A cég
        // számlájára hivatkozik. Az InvoiceService::cancel() ezt sosem hozná
        // létre — közvetlen Eloquent create()-tel szimuláljuk sérült adatként.
        Invoice::withoutGlobalScope('company')->create([
            'company_id' => $this->companyB->id,
            'partner_id' => $this->partnerB->id,
            'document_series_id' => $this->seriesB->id,
            'invoice_number' => 'BB-'.now()->format('Ym').'-999001',
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'status' => InvoiceStatus::Storno,
            'payment_status' => PaymentStatus::Open,
            'storno_of_invoice_id' => $invoiceA->id,
            'net_total' => 0,
            'vat_total' => 0,
            'gross_total' => 0,
            'gross_total_base_currency' => 0,
        ]);

        $response = $this->inCompany($this->user, $this->companyA)
            ->getJson('/api/invoices');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $invoiceA->id);
        $this->assertNotNull($row, 'Az A cég számlájának szerepelnie kell a listában');
        $this->assertFalse($row['has_storno'],
            'A B cégbeli, A cég számlájára mutató sztornó nem jelenhet meg has_storno=true-ként A cég kontextusában');
    }

    // ─── G: lista — pozitív eset ─────────────────────────────────────────────

    public function test_list_has_storno_reflects_own_company_storno(): void
    {
        $invoiceWithStorno = $this->makeInvoice($this->companyA, $this->seriesA, $this->partnerA);
        $invoiceWithoutStorno = $this->makeInvoice($this->companyA, $this->seriesA, $this->partnerA);

        app(InvoiceService::class)->cancel($invoiceWithStorno, $this->user);

        $response = $this->inCompany($this->user, $this->companyA)
            ->getJson('/api/invoices');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($rows[$invoiceWithStorno->id]['has_storno']);
        $this->assertFalse($rows[$invoiceWithoutStorno->id]['has_storno']);
    }

    // ─── Segédmetódusok ──────────────────────────────────────────────────────

    private function inCompany(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }

    private function makeCompany(string $name, string $taxNumber): Company
    {
        return Company::create([
            'name' => $name,
            'tax_number' => $taxNumber,
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000',
            'city' => 'Budapest',
            'address_line' => 'Teszt u. 1.',
            'country_code' => 'HU',
            'base_currency' => 'HUF',
        ]);
    }

    private function makeInvoice(Company $company, DocumentSeries $series, Partner $partner): Invoice
    {
        self::$docSeq++;

        $invoice = Invoice::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => sprintf('%s-%s-%06d', $series->prefix, now()->format('Ym'), self::$docSeq),
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => 10000.00,
            'vat_total' => 2700.00,
            'gross_total' => 12700.00,
            'gross_total_base_currency' => 12700.00,
            'created_by' => $this->user->id,
        ]);

        $invoice->items()->create([
            'description' => 'Teszt tétel',
            'quantity' => 1.0,
            'unit' => 'db',
            'unit_price' => 10000.0,
            'vat_rate_id' => $this->vatRate->id,
            'net_amount' => 10000.0,
            'vat_amount' => 2700.0,
            'gross_amount' => 12700.0,
            'sort_order' => 0,
        ]);

        return $invoice;
    }
}
