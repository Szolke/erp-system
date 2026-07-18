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
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice::scopeNotCancelled() — a sztornó-kizárás a stornos() relációval dől el,
 * NEM a status mezővel (sztornózáskor az eredeti invoice status-a `issued` marad,
 * l. InvoiceService::cancel()).
 */
class InvoiceScopeTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private Partner $partner;
    private PaymentMethod $paymentMethod;
    private DocumentSeries $series;

    protected function setUp(): void
    {
        parent::setUp();

        self::$seq++;

        $this->company = Company::create([
            'name'                => 'Scope Teszt Kft. '.self::$seq,
            'tax_number'          => '1111111'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Scope u. '.self::$seq.'.',
            'base_currency'       => 'HUF',
        ]);

        app(CurrentCompany::class)->set($this->company->id);

        $this->partner = Partner::create([
            'type'                 => 'customer',
            'name'                 => 'Teszt Vevő',
            'billing_postal_code'  => '1000',
            'billing_city'         => 'Budapest',
            'billing_address_line' => 'Fő u. 1.',
            'default_currency'     => 'HUF',
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'code'      => 'CASH'.self::$seq,
            'name'      => 'Készpénz',
            'is_active' => true,
        ]);

        $this->series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id'    => $this->company->id,
            'document_type' => DocumentType::Invoice,
            'prefix'        => 'SZ',
            'reset_yearly'  => true,
            'next_number'   => 1,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_not_cancelled_excludes_invoice_with_a_storno(): void
    {
        $original = $this->makeInvoice('SZ-000001');
        $storno   = $this->makeInvoice('SZSZT-000001', status: InvoiceStatus::Storno);
        $storno->update(['storno_of_invoice_id' => $original->id]);

        $result = Invoice::notCancelled()->pluck('id');

        $this->assertFalse($result->contains($original->id), 'A sztornózott eredeti számla nem jelenhet meg.');
    }

    public function test_not_cancelled_keeps_invoice_without_a_storno(): void
    {
        $invoice = $this->makeInvoice('SZ-000002');

        $result = Invoice::notCancelled()->pluck('id');

        $this->assertTrue($result->contains($invoice->id), 'A nem sztornózott számlának meg kell jelennie.');
    }

    private function makeInvoice(string $number, InvoiceStatus $status = InvoiceStatus::Issued): Invoice
    {
        return Invoice::create([
            'company_id'                => $this->company->id,
            'partner_id'                => $this->partner->id,
            'document_series_id'        => $this->series->id,
            'invoice_number'            => $number,
            'issue_date'                => now()->toDateString(),
            'fulfillment_date'          => now()->toDateString(),
            'due_date'                  => now()->toDateString(),
            'currency'                  => 'HUF',
            'exchange_rate'             => 1.0,
            'exchange_rate_date'        => now()->toDateString(),
            'payment_method_id'         => $this->paymentMethod->id,
            'status'                    => $status,
            'payment_status'            => PaymentStatus::Open,
            'net_total'                 => 1000.00,
            'vat_total'                 => 270.00,
            'gross_total'               => 1270.00,
            'gross_total_base_currency' => 1270.00,
        ]);
    }
}
