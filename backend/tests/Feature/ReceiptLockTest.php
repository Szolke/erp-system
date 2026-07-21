<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\ReceiptStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptReport;
use App\Models\VatRate;
use App\Services\Enyugta\ReceiptAlreadyReportedException;
use App\Services\Enyugta\ReceiptReportBuilder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D2 (nyugta-zárolás): egyszer jelentett nyugta nem módosítható és nem
 * törölhető — a Receipt modell saving/deleting guardja kényszeríti ki.
 * A guard NEM akaszthatja meg magát az aggregációt (ReceiptReportBuilder),
 * ami a reported_at/receipt_report_id mezőket írja.
 */
class ReceiptLockTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    private Company $company;
    private PaymentMethod $paymentMethod;
    private DocumentSeries $receiptSeries;
    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        self::$seq++;

        $this->company = Company::withoutGlobalScope('company')->create([
            'name'                => 'eNyugta Lock Kft. '.self::$seq,
            'tax_number'          => '1112223'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Zár u. '.self::$seq.'.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        app(CurrentCompany::class)->set($this->company->id);

        $this->paymentMethod = PaymentMethod::create([
            'code'      => 'CASH'.self::$seq,
            'name'      => 'Készpénz',
            'is_active' => true,
        ]);

        $this->receiptSeries = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id'    => $this->company->id,
            'document_type' => DocumentType::Receipt,
            'prefix'        => 'NY',
            'reset_yearly'  => true,
            'next_number'   => 1,
        ]);

        $this->vatRate = VatRate::create([
            'name' => '27% '.self::$seq,
            'rate_percent' => 27.0,
            'nav_code' => 'CODE'.self::$seq,
            'nav_receipt_category' => '27%',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_unreported_receipt_can_be_updated(): void
    {
        $receipt = $this->makeReceipt();

        $receipt->net_total = 20000;
        $receipt->save();

        $this->assertSame('20000.00', (string) $receipt->fresh()->net_total);
    }

    public function test_unreported_receipt_can_be_deleted(): void
    {
        $receipt = $this->makeReceipt();
        $id = $receipt->id;

        $receipt->delete();

        $this->assertNull(Receipt::withoutGlobalScope('company')->find($id));
    }

    public function test_reported_receipt_update_is_blocked(): void
    {
        $receipt = $this->makeReceipt();
        $receipt->update(['reported_at' => now()]);

        $this->expectException(ReceiptAlreadyReportedException::class);

        $receipt->net_total = 99999;
        $receipt->save();
    }

    public function test_reported_receipt_delete_is_blocked(): void
    {
        $receipt = $this->makeReceipt();
        $receipt->update(['reported_at' => now()]);

        $this->expectException(ReceiptAlreadyReportedException::class);

        $receipt->delete();
    }

    public function test_updating_only_reported_at_and_receipt_report_id_is_allowed_even_when_already_reported(): void
    {
        $receipt = $this->makeReceipt();
        $receipt->update(['reported_at' => now()]);

        $report = ReceiptReport::withoutGlobalScope('company')->create([
            'company_id' => $this->company->id,
            'report_date' => now()->toDateString(),
            'type' => 'normal',
            'status' => 'draft',
            'receipt_count' => 0,
            'total_net' => 0,
            'total_vat' => 0,
            'total_gross' => 0,
            'generated_at' => now(),
        ]);

        // Csak a report-mezőket írjuk — ennek ÁT KELL mennie a guardon.
        $receipt->receipt_report_id = $report->id;
        $receipt->save();

        $this->assertSame($report->id, $receipt->fresh()->receipt_report_id);
    }

    public function test_aggregation_itself_passes_through_the_guard(): void
    {
        $this->makeReceipt();

        // Első build: minden nyugta most kerül jelentésre — a guard nem
        // akadhat el, mert ezek a nyugták ELŐTTE nem voltak jelentve.
        $report = app(ReceiptReportBuilder::class)->build($this->company, now());

        $this->assertSame(1, $report->receipt_count);

        // Második build ugyanarra a napra (pl. egy plusz nyugta miatt) — a
        // MÁR jelentett nyugtákat is újra kell tudnia "érinteni" (idempotens
        // no-op vagy report_id átpontozás), a guard itt sem akadhat el.
        $this->makeReceipt();
        $report2 = app(ReceiptReportBuilder::class)->build($this->company, now());

        $this->assertSame(2, $report2->receipt_count);
    }

    private function makeReceipt(): Receipt
    {
        self::$docSeq++;

        $receipt = Receipt::create([
            'company_id' => $this->company->id,
            'document_series_id' => $this->receiptSeries->id,
            'receipt_number' => sprintf('NY-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => now()->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'status' => ReceiptStatus::Issued,
            'net_total' => 10000,
            'vat_total' => 2700,
            'gross_total' => 12700,
        ]);

        $receipt->items()->create([
            'description' => 'Teszt tétel',
            'quantity' => 1,
            'unit_price' => 10000,
            'vat_rate_id' => $this->vatRate->id,
            'net_amount' => 10000,
            'vat_amount' => 2700,
            'gross_amount' => 12700,
            'sort_order' => 0,
        ]);

        return $receipt;
    }
}
