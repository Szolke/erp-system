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
use App\Services\DashboardService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DashboardService — widget-számítások. A `days_overdue` mező (oldestUnpaid())
 * SZÁNDÉKOSAN előjeles: pozitív = lejárt, negatív = még csak ezután esedékes
 * (l. DashboardService::daysOverdue() komment). Ez a teszt épp ezt az előjelet
 * védi — ha valaki a jövőben abszolút értékre "javítaná", itt buknia kell.
 */
class DashboardServiceTest extends TestCase
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
            'name'                => 'Dashboard Teszt Kft. '.self::$seq,
            'tax_number'          => '2222222'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Dashboard u. '.self::$seq.'.',
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

    public function test_oldest_unpaid_days_overdue_is_negative_for_a_future_due_date(): void
    {
        $this->makeInvoice('SZ-000001', dueDate: now()->addDays(10)->toDateString());

        $items = app(DashboardService::class)->oldestUnpaid($this->company);

        $this->assertCount(1, $items);
        $this->assertSame(-10, $items[0]['days_overdue']);
    }

    public function test_oldest_unpaid_days_overdue_is_positive_for_a_past_due_date(): void
    {
        $this->makeInvoice('SZ-000002', dueDate: now()->subDays(7)->toDateString());

        $items = app(DashboardService::class)->oldestUnpaid($this->company);

        $this->assertCount(1, $items);
        $this->assertSame(7, $items[0]['days_overdue']);
    }

    private function makeInvoice(string $number, string $dueDate): Invoice
    {
        return Invoice::create([
            'company_id'                => $this->company->id,
            'partner_id'                => $this->partner->id,
            'document_series_id'        => $this->series->id,
            'invoice_number'            => $number,
            'issue_date'                => now()->toDateString(),
            'fulfillment_date'          => now()->toDateString(),
            'due_date'                  => $dueDate,
            'currency'                  => 'HUF',
            'exchange_rate'             => 1.0,
            'exchange_rate_date'        => now()->toDateString(),
            'payment_method_id'         => $this->paymentMethod->id,
            'status'                    => InvoiceStatus::Issued,
            'payment_status'            => PaymentStatus::Open,
            'net_total'                 => 1000.00,
            'vat_total'                 => 270.00,
            'gross_total'               => 1270.00,
            'gross_total_base_currency' => 1270.00,
        ]);
    }
}
