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
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Models\VatRate;
use App\Services\InvoiceService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GET /api/reports/products — a számlatételekből (NEM a products törzsből)
 * épülő termék-riport: törölt termék historikus névvel, teljes (nem
 * limitelt) totals-blokk, rendezési irányok, storno-nettósítás.
 */
class ReportProductsTest extends TestCase
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
        // RefreshDatabase a Postgres-t resetálja, a Redist NEM — l. ReportGatingTest.
        \Illuminate\Support\Facades\Cache::store('redis')->tags(['reports'])->flush();
        Queue::fake();

        self::$seq++;
        $this->company = Company::create([
            'name' => 'Termékriport Kft. '.self::$seq,
            'tax_number' => '2222222'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'Termék u. 1.',
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($this->company->id);
        $this->partner = Partner::create([
            'type' => 'customer', 'name' => 'Termék Partner',
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);
        $this->paymentMethod = PaymentMethod::create(['code' => 'TERMCASH'.self::$seq, 'name' => 'Készpénz', 'is_active' => true]);
        $this->vatRate = VatRate::create(['name' => 'ÁFA 27% '.self::$seq, 'rate_percent' => 27.00, 'nav_code' => '27', 'is_active' => true]);
        $this->series = DocumentSeries::create([
            'document_type' => DocumentType::Invoice, 'prefix' => 'TR'.self::$seq, 'reset_yearly' => true, 'next_number' => 1,
        ]);
        app(CurrentCompany::class)->clear();

        $module = Module::create([
            'key' => 'reports', 'name' => 'Kimutatások', 'description' => 'test',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 20,
        ]);
        $this->company->enabledModules()->attach($module->id, ['enabled' => true]);

        $this->superadmin = User::create([
            'name' => 'Termék Admin', 'email' => 'report.products.'.self::$seq.'@example.com',
            'password' => bcrypt('password'), 'is_superadmin' => true,
        ]);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_deleted_product_still_appears_with_the_historic_name(): void
    {
        app(CurrentCompany::class)->set($this->company->id);
        $product = Product::create([
            'sku' => 'SKU-1', 'name' => 'Eredeti termék név', 'unit' => 'db', 'type' => 'product',
            'vat_rate_id' => $this->vatRate->id, 'base_price' => 1000, 'base_currency' => 'HUF',
        ]);
        app(CurrentCompany::class)->clear();

        $this->makeInvoiceWithItem([
            'product_id' => $product->id, 'description' => 'Eredeti termék név', 'unit' => 'db',
            'quantity' => 2, 'net_amount' => 2000, 'vat_amount' => 540, 'gross_amount' => 2540,
        ], ['issue_date' => '2026-03-05', 'fulfillment_date' => '2026-03-05']);

        $product->delete(); // hard delete — invoice_items.product_id nullOnDelete()

        $data = $this->reportAs('2026-03', '2026-03');

        $this->assertCount(1, $data['items']);
        $this->assertNull($data['items'][0]['product_id'], 'A termék törlésekor a product_id nullOnDelete-tel NULL-lá válik');
        $this->assertSame('Eredeti termék név', $data['items'][0]['name'], 'A megjelenített név a tételből (historikus), nem a törölt products sorból jön');
        $this->assertEqualsWithDelta(2000.0, $data['items'][0]['net_revenue'], 0.001);
    }

    public function test_totals_reflect_the_full_filtered_set_not_just_the_limited_page(): void
    {
        $this->makeInvoiceWithItem(
            ['description' => 'Termék A', 'unit' => 'db', 'quantity' => 1, 'net_amount' => 1000, 'vat_amount' => 270, 'gross_amount' => 1270],
            ['issue_date' => '2026-04-05', 'fulfillment_date' => '2026-04-05']
        );
        $this->makeInvoiceWithItem(
            ['description' => 'Termék B', 'unit' => 'db', 'quantity' => 1, 'net_amount' => 3000, 'vat_amount' => 810, 'gross_amount' => 3810],
            ['issue_date' => '2026-04-06', 'fulfillment_date' => '2026-04-06']
        );

        $data = $this->reportAs('2026-04', '2026-04', limit: 1);

        $this->assertCount(1, $data['items'], 'A lapozott lista csak 1 elemet ad');
        $this->assertEqualsWithDelta(4000.0, $data['totals']['net_revenue'], 0.001, 'A totals a TELJES (nem limitelt) halmazra vonatkozik');
    }

    public function test_order_by_quantity_differs_from_order_by_revenue(): void
    {
        $this->makeInvoiceWithItem(
            ['description' => 'Drága kevés', 'unit' => 'db', 'quantity' => 1, 'net_amount' => 10000, 'vat_amount' => 2700, 'gross_amount' => 12700],
            ['issue_date' => '2026-05-05', 'fulfillment_date' => '2026-05-05']
        );
        $this->makeInvoiceWithItem(
            ['description' => 'Olcsó sok', 'unit' => 'db', 'quantity' => 100, 'net_amount' => 1000, 'vat_amount' => 270, 'gross_amount' => 1270],
            ['issue_date' => '2026-05-06', 'fulfillment_date' => '2026-05-06']
        );

        $byRevenue = $this->reportAs('2026-05', '2026-05', orderBy: 'revenue');
        $byQuantity = $this->reportAs('2026-05', '2026-05', orderBy: 'quantity');

        $this->assertSame('Drága kevés', $byRevenue['items'][0]['name']);
        $this->assertSame('Olcsó sok', $byQuantity['items'][0]['name']);
    }

    public function test_storno_nets_out_product_revenue(): void
    {
        $invoice = $this->makeInvoiceWithItem(
            ['description' => 'Sztornózandó termék', 'unit' => 'db', 'quantity' => 1, 'net_amount' => 5000, 'vat_amount' => 1350, 'gross_amount' => 6350],
            ['issue_date' => '2026-06-05', 'fulfillment_date' => '2026-06-05']
        );

        // A cancel() a storno dátumait now()-ból veszi — a tényleges tesztfutási
        // dátum helyett ugyanabba a hónapba kell rögzíteni, hogy a riport
        // tartománya mindkét (eredeti + storno) sort lefedje.
        \Illuminate\Support\Carbon::setTestNow('2026-06-10');
        app(CurrentCompany::class)->set($this->company->id);
        app(InvoiceService::class)->cancel($invoice, $this->superadmin);
        app(CurrentCompany::class)->clear();
        \Illuminate\Support\Carbon::setTestNow();

        $data = $this->reportAs('2026-06', '2026-06');

        $this->assertEqualsWithDelta(0.0, $data['totals']['net_revenue'], 0.001);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function reportAs(string $from, string $to, ?int $limit = null, ?string $orderBy = null): array
    {
        $query = ['from' => $from, 'to' => $to];
        if ($limit !== null) $query['limit'] = $limit;
        if ($orderBy !== null) $query['order_by'] = $orderBy;

        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/reports/products?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeInvoiceWithItem(array $item, array $invoiceOverrides = []): Invoice
    {
        self::$docSeq++;

        $netTotal = $item['net_amount'];
        $vatTotal = $item['vat_amount'];
        $grossTotal = $item['gross_amount'];

        $invoice = Invoice::create(array_merge([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'document_series_id' => $this->series->id,
            'invoice_number' => sprintf('TR-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => $netTotal,
            'vat_total' => $vatTotal,
            'gross_total' => $grossTotal,
            'gross_total_base_currency' => $grossTotal,
        ], $invoiceOverrides));

        $invoice->items()->create(array_merge([
            'unit_price' => $item['net_amount'] / max($item['quantity'], 0.001),
            'vat_rate_id' => $this->vatRate->id,
            'sort_order' => 0,
        ], $item));

        return $invoice;
    }
}
