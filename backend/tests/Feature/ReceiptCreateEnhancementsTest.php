<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Models\VatRate;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ReceiptCreatePage backend-oldali bővítés-tesztek.
 *
 * Tesztelt esetek:
 *  1. Nyugta kiállítható partnerrel (partner_id megadva).
 *  2. Nyugta kiállítható partner nélkül — névtelen nyugta (partner_id null).
 *  3. Nyugta kiállítható termék-alapú tételsorral (product_id megadva).
 *  4. Nyugta kiállítható nem-HUF devizával + árfolyammal.
 *  5. fulfillment_date mentődik, ha meg van adva.
 *  6. fulfillment_date az issue_date értékére esik, ha üres.
 */
class ReceiptCreateEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company       $company;
    private User          $user;
    private VatRate       $vatRate;
    private PaymentMethod $paymentMethod;
    private Partner       $partner;
    private Product       $product;
    private DocumentSeries $receiptSeries;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        self::$seq++;

        $this->company = Company::create([
            'name'                => 'Nyugta Teszt Kft. ' . self::$seq,
            'tax_number'          => '3333333' . self::$seq . '-2-41',
            'registration_number' => '01-09-' . str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $this->user = User::create([
            'name'          => 'Nyugta Tesztelő ' . self::$seq,
            'email'         => 'receipt.test.' . self::$seq . '@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);

        $this->user->companies()->attach($this->company->id, ['is_default' => true]);

        $this->vatRate = VatRate::create([
            'name'         => 'ÁFA 27%',
            'rate_percent' => 27.00,
            'nav_code'     => '27',
            'is_active'    => true,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'code'      => 'CASH' . self::$seq,
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

        $this->product = Product::create([
            'sku'           => 'PROD-' . self::$seq,
            'name'          => 'Teszt Termék',
            'unit'          => 'db',
            'type'          => ProductType::Service,
            'vat_rate_id'   => $this->vatRate->id,
            'base_price'    => 5000,
            'base_currency' => 'HUF',
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

    // ── 1. Partnerrel ─────────────────────────────────────────────────────────

    public function test_receipt_can_be_created_with_partner(): void
    {
        $response = $this->inCompany()->postJson('/api/receipts', [
            'partner_id'        => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'issue_date'        => '2026-07-04',
            'currency'          => 'HUF',
            'items'             => $this->oneItem(),
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame($this->partner->id, $data['partner_id']);
        $this->assertSame($this->partner->name, $data['partner']['name']);
    }

    // ── 2. Partner nélkül (névtelen nyugta) ───────────────────────────────────

    public function test_receipt_can_be_created_without_partner(): void
    {
        $response = $this->inCompany()->postJson('/api/receipts', [
            'partner_id'        => null,
            'payment_method_id' => $this->paymentMethod->id,
            'issue_date'        => '2026-07-04',
            'currency'          => 'HUF',
            'items'             => $this->oneItem(),
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertNull($data['partner_id']);
        $this->assertNull($data['partner']);
    }

    // ── 3. Termék-alapú tételsorral ───────────────────────────────────────────

    public function test_receipt_can_be_created_with_product_based_item(): void
    {
        $response = $this->inCompany()->postJson('/api/receipts', [
            'payment_method_id' => $this->paymentMethod->id,
            'issue_date'        => '2026-07-04',
            'currency'          => 'HUF',
            'items'             => [[
                'product_id'   => $this->product->id,
                'description'  => $this->product->name,
                'quantity'     => 2,
                'unit_price'   => 5000,
                'vat_rate_id'  => $this->vatRate->id,
            ]],
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame($this->product->id, $data['items'][0]['product_id']);
        // 2 × 5000 × 1.27 = 12 700
        $this->assertEquals(12700, (float) $data['gross_total']);
    }

    // ── 4. Nem-HUF devizával (EUR + árfolyam) ────────────────────────────────

    public function test_receipt_can_be_created_with_non_huf_currency(): void
    {
        $response = $this->inCompany()->postJson('/api/receipts', [
            'payment_method_id' => $this->paymentMethod->id,
            'issue_date'        => '2026-07-04',
            'currency'          => 'EUR',
            'exchange_rate'     => 410.50,
            'items'             => $this->oneItem(),
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame('EUR', $data['currency']);
        $this->assertEquals(410.50, (float) $data['exchange_rate']);
    }

    // ── 5. fulfillment_date mentődik, ha meg van adva ─────────────────────────

    public function test_fulfillment_date_is_saved_when_provided(): void
    {
        $response = $this->inCompany()->postJson('/api/receipts', [
            'payment_method_id' => $this->paymentMethod->id,
            'issue_date'        => '2026-07-04',
            'fulfillment_date'  => '2026-07-01',
            'currency'          => 'HUF',
            'items'             => $this->oneItem(),
        ]);

        $response->assertCreated();
        $this->assertSame('2026-07-01', $response->json('data.fulfillment_date'));
    }

    // ── 6. fulfillment_date → issue_date default, ha üres ─────────────────────

    public function test_fulfillment_date_defaults_to_issue_date_when_omitted(): void
    {
        $response = $this->inCompany()->postJson('/api/receipts', [
            'payment_method_id' => $this->paymentMethod->id,
            'issue_date'        => '2026-07-04',
            'currency'          => 'HUF',
            'items'             => $this->oneItem(),
        ]);

        $response->assertCreated();
        $this->assertSame('2026-07-04', $response->json('data.fulfillment_date'));
    }

    // ── Segédfüggvények ───────────────────────────────────────────────────────

    private function inCompany(): static
    {
        return $this->actingAs($this->user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }

    private function oneItem(): array
    {
        return [[
            'description' => 'Teszt tétel',
            'quantity'    => 1,
            'unit_price'  => 1000,
            'vat_rate_id' => $this->vatRate->id,
        ]];
    }
}
