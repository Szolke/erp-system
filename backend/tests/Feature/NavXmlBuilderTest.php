<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\NavEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\VatRate;
use App\Services\Nav\NavXmlBuilder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NavOnlineInvoice\Config;
use Tests\TestCase;

/**
 * Validates NavXmlBuilder's output against the ACTUAL bundled NAV 3.0 schema
 * (vendor/pzs/nav-online-invoice .../xsd/invoiceData.xsd via DOMDocument::
 * schemaValidate() — the same mechanism the vendor lib itself uses, l. Xsd.php)
 * — not a hand-picked set of element-order assertions, which would only catch
 * the specific bug already found, not the next one. This test exists because a
 * real NAV test-environment submission caught structural bugs that no prior
 * test had covered — see NavXmlBuilder's class docblock.
 */
class NavXmlBuilderTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function assertBuildsSchemaValidXml(Invoice $invoice): void
    {
        $xml = app(NavXmlBuilder::class)->build($invoice);
        $xmlString = $xml->asXML();

        $doc = new \DOMDocument();
        $doc->loadXML($xmlString);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $isValid = $doc->schemaValidate(Config::getDataXsdFilename());
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue(
            $isValid,
            "A NavXmlBuilder kimenete nem felel meg a NAV invoiceData.xsd sémának:\n"
            .implode("\n", array_map(fn ($e) => trim($e->message), $errors))
        );
    }

    /**
     * @return array{0: Company, 1: VatRate, 2: Invoice}
     */
    private function makeInvoiceFixture(): array
    {
        self::$seq++;
        $n = self::$seq;

        $company = Company::create([
            'name' => 'XSD Teszt Kft. '.$n,
            'tax_number' => '1111111'.$n.'-1-42',
            'registration_number' => '01-09-'.str_pad($n, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000',
            'city' => 'Budapest',
            'address_line' => 'Teszt utca 1.',
            'base_currency' => 'HUF',
            'nav_environment' => NavEnvironment::Test,
        ]);

        app(CurrentCompany::class)->set($company->id);

        $vatRate = VatRate::create([
            'name' => 'ÁFA 27%',
            'rate_percent' => 27.00,
            'nav_code' => '27',
            'is_active' => true,
        ]);

        $paymentMethod = PaymentMethod::create([
            'code' => 'CASH'.$n,
            'name' => 'Készpénz',
            'is_active' => true,
        ]);

        $partner = Partner::create([
            'type' => 'customer',
            'name' => 'Teszt Vevő Kft.',
            'billing_postal_code' => '1010',
            'billing_city' => 'Budapest',
            'billing_address_line' => 'Vevő utca 2.',
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
            'email' => 'navxml.user'.$n.'@example.com',
            'password' => bcrypt('password'),
        ]);

        $invoice = Invoice::create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => sprintf('SZ-2026%02d-%06d', ($n % 12) + 1, $n),
            'issue_date' => '2026-07-20',
            'fulfillment_date' => '2026-07-20',
            'due_date' => '2026-08-19',
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => '2026-07-20',
            'payment_method_id' => $paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => 150000.00,
            'vat_total' => 40500.00,
            'gross_total' => 190500.00,
            'gross_total_base_currency' => 190500.00,
            'created_by' => $user->id,
        ]);

        return [$company, $vatRate, $invoice];
    }

    public function test_build_produces_schema_valid_xml(): void
    {
        [, $vatRate, $invoice] = $this->makeInvoiceFixture();

        $invoice->items()->create([
            'description' => 'Tanácsadás',
            'quantity' => 10,
            'unit' => 'óra',
            'unit_price' => 15000.0,
            'vat_rate_id' => $vatRate->id,
            'net_amount' => 150000.0,
            'vat_amount' => 40500.0,
            'gross_amount' => 190500.0,
            'sort_order' => 0,
        ]);

        $this->assertBuildsSchemaValidXml($invoice);
    }

    /**
     * A `discountValue` a NAV séma szerint MonetaryType (pénzösszeg), a `discountRate`
     * pedig egy KÜLÖN, 0-1 közötti RateType — a korábbi kód a százalékot "10.00%"
     * stringként a discountValue-ba írta, ami valódi NAV-teszt-beküldés során bukott
     * el ("is not a valid value of the atomic type MonetaryType"). Ezt a sémavalidáció
     * csak akkor kapja el, ha a teszt-fixture ténylegesen tartalmaz kedvezményes tételt.
     */
    public function test_build_produces_schema_valid_xml_with_discounted_item(): void
    {
        [, $vatRate, $invoice] = $this->makeInvoiceFixture();

        $invoice->items()->create([
            'description' => 'Tanácsadás kedvezménnyel',
            'quantity' => 10,
            'unit' => 'óra',
            'unit_price' => 15000.0,
            'discount_percent' => 10.0,
            'vat_rate_id' => $vatRate->id,
            'net_amount' => 135000.0, // 10 × 15000 × (1 − 0.10)
            'vat_amount' => 36450.0,
            'gross_amount' => 171450.0,
            'sort_order' => 0,
        ]);

        $this->assertBuildsSchemaValidXml($invoice);
    }
}
