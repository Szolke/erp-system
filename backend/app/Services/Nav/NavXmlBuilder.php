<?php

namespace App\Services\Nav;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\VatRate;
use Illuminate\Support\Collection;
use SimpleXMLElement;

/**
 * Builds a NAV Online Számla 3.0 invoiceData.xsd-conformant SimpleXMLElement
 * from a fully-loaded Invoice model.
 *
 * Element order within each type is checked against the actual bundled schema
 * (vendor/pzs/nav-online-invoice/src/NavOnlineInvoice/xsd/invoiceData.xsd) —
 * NOT guessed. Real NAV test-environment submissions caught bugs no prior test
 * covered (all fixed, each now guarded by NavXmlBuilderTest's schema-validation
 * tests): lineVatRate belongs inside lineAmountsNormal (2nd child, after
 * lineNetAmountData), not directly under <line>; invoiceNetAmount/
 * invoiceVatAmount are direct children of summaryNormal (after the
 * summaryByVatRate entries) — summaryGrossData is a SIBLING of summaryNormal
 * that carries only invoiceGrossAmount/invoiceGrossAmountHUF, not net/vat data;
 * and discountValue is a MonetaryType (the discount's forint amount) while the
 * percentage belongs in the separate discountRate (RateType, 0-1) — NOT a
 * "10.00%" string stuffed into discountValue.
 *
 * STILL OPEN — NAV SANDBOX VERIFICATION REQUIRED:
 * the VatExemption `case` element (AAM, TAM, …) is a free-text field in the
 * XSD (DetailedReasonType — no enumeration to check against), so the XSD
 * cannot confirm whether these are the NAV-accepted case codes; that is a
 * business-rule question only NAV's actual acceptance can answer. Test using
 * the NAV test environment (api-test.onlineszamla.nav.gov.hu).
 */
class NavXmlBuilder
{
    private const NS_DATA = 'http://schemas.nav.gov.hu/OSA/3.0/data';
    private const NS_BASE = 'http://schemas.nav.gov.hu/OSA/3.0/base';

    /** Payment method code → NAV PaymentMethodType */
    private const PAYMENT_METHOD_MAP = [
        'cash' => 'CASH',
        'card' => 'CARD',
        'bank_transfer' => 'TRANSFER',
        'simplepay' => 'CARD',
    ];

    /** Internal unit string → NAV UnitOfMeasureType */
    private const UNIT_MAP = [
        'db' => 'PIECE',
        'piece' => 'PIECE',
        'kg' => 'KILOGRAM',
        'kilogram' => 'KILOGRAM',
        'tonna' => 'TON',
        'kwh' => 'KWH',
        'nap' => 'DAY',
        'day' => 'DAY',
        'óra' => 'HOUR',
        'ora' => 'HOUR',
        'hour' => 'HOUR',
        'perc' => 'MINUTE',
        'minute' => 'MINUTE',
        'hónap' => 'MONTH',
        'honap' => 'MONTH',
        'month' => 'MONTH',
        'liter' => 'LITER',
        'liter' => 'LITER',
        'km' => 'KILOMETER',
        'kilometer' => 'KILOMETER',
        'm3' => 'CUBIC_METER',
        'm' => 'METER',
        'meter' => 'METER',
    ];

    public function build(Invoice $invoice): SimpleXMLElement
    {
        $invoice->loadMissing(['company', 'partner', 'paymentMethod', 'items.vatRate']);

        $xml = new SimpleXMLElement(sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><InvoiceData xmlns="%s" xmlns:base="%s"/>',
            self::NS_DATA,
            self::NS_BASE
        ));

        $xml->addChild('invoiceNumber', $invoice->invoice_number);
        $xml->addChild('invoiceIssueDate', $invoice->issue_date->format('Y-m-d'));
        // false = data reporting only (invoice exists externally as paper/PDF, not e-invoice)
        $xml->addChild('completenessIndicator', 'false');

        $invoiceMain = $xml->addChild('invoiceMain');
        $invoiceEl = $invoiceMain->addChild('invoice');

        $this->addInvoiceHead($invoiceEl, $invoice);
        $this->addInvoiceLines($invoiceEl, $invoice);
        $this->addInvoiceSummary($invoiceEl, $invoice);

        return $xml;
    }

    private function addInvoiceHead(SimpleXMLElement $parent, Invoice $invoice): void
    {
        $head = $parent->addChild('invoiceHead');
        $company = $invoice->company;
        $partner = $invoice->partner;

        $supplierInfo = $head->addChild('supplierInfo');
        $this->addTaxNumber($supplierInfo, 'supplierTaxNumber', $company->tax_number);
        $supplierInfo->addChild('supplierName', htmlspecialchars($company->name));
        $supplierAddress = $supplierInfo->addChild('supplierAddress');
        $this->addSimpleAddress($supplierAddress, $company->postal_code, $company->city, $company->address_line, $company->country_code);

        $customerInfo = $head->addChild('customerInfo');
        $vatStatus = $this->resolveCustomerVatStatus($partner);
        $customerInfo->addChild('customerVatStatus', $vatStatus);

        if ($vatStatus === 'DOMESTIC' && $partner->tax_number) {
            $customerVatData = $customerInfo->addChild('customerVatData');
            $customerTaxNumber = $customerVatData->addChild('customerTaxNumber');
            $this->addTaxNumberFields($customerTaxNumber, $partner->tax_number);
        } elseif ($vatStatus === 'OTHER' && $partner->eu_tax_number) {
            $customerVatData = $customerInfo->addChild('customerVatData');
            $communityVatNumber = $customerVatData->addChild('communityVatNumber');
            $communityVatNumber->addChild('base:taxpayerId', $partner->eu_tax_number, self::NS_BASE);
        }

        if ($partner->name) {
            $customerInfo->addChild('customerName', htmlspecialchars($partner->name));
        }

        if ($partner->billing_city) {
            $customerAddress = $customerInfo->addChild('customerAddress');
            $this->addSimpleAddress($customerAddress, $partner->billing_postal_code, $partner->billing_city, $partner->billing_address_line);
        }

        $detail = $head->addChild('invoiceDetail');
        $detail->addChild('invoiceCategory', 'NORMAL');
        $detail->addChild('invoiceDeliveryDate', $invoice->fulfillment_date->format('Y-m-d'));
        $detail->addChild('currencyCode', $invoice->currency);
        $detail->addChild('exchangeRate', number_format((float) $invoice->exchange_rate, 6, '.', ''));
        $detail->addChild('paymentMethod', $this->mapPaymentMethod($invoice->paymentMethod?->code));
        $detail->addChild('paymentDate', $invoice->due_date->format('Y-m-d'));
        $detail->addChild('invoiceAppearance', 'PAPER');
    }

    private function addInvoiceLines(SimpleXMLElement $parent, Invoice $invoice): void
    {
        $lines = $parent->addChild('invoiceLines');
        $lines->addChild('mergedItemIndicator', 'false');

        foreach ($invoice->items as $item) {
            $exchangeRate = (float) $invoice->exchange_rate;
            $isHuf = ($invoice->currency === 'HUF');

            // Sorrend a NAV invoiceData.xsd LineType szekvenciája szerint (vendor
            // pzs/nav-online-invoice xsd/invoiceData.xsd — NEM találgatva):
            // lineNumber, lineExpressionIndicator, lineDescription, quantity,
            // unitOfMeasure(+Own), unitPrice(+HUF), lineDiscountData, lineAmountsNormal.
            $line = $lines->addChild('line');
            $line->addChild('lineNumber', (string) ($item->sort_order + 1));

            $unitMapped = $this->mapUnit(strtolower(trim($item->unit)));

            // Kötelező mező (LineType-ban NINCS minOccurs="0"): true, ha a mennyiségi
            // egység természetes NAV-mértékegységben (nem 'OWN') fejezhető ki.
            $line->addChild('lineExpressionIndicator', $unitMapped !== 'OWN' ? 'true' : 'false');

            $line->addChild('lineDescription', htmlspecialchars($item->description));
            $line->addChild('quantity', rtrim(rtrim(number_format((float) $item->quantity, 10, '.', ''), '0'), '.'));

            if ($unitMapped !== 'OWN') {
                $line->addChild('unitOfMeasure', $unitMapped);
            } else {
                $line->addChild('unitOfMeasure', 'OWN');
                $line->addChild('unitOfMeasureOwn', htmlspecialchars($item->unit));
            }

            $line->addChild('unitPrice', number_format((float) $item->unit_price, 2, '.', ''));
            $line->addChild('unitPriceHUF', $this->fmtHuf($item->unit_price, $exchangeRate, $isHuf));

            if ((float) $item->discount_percent > 0) {
                // discountValue = MonetaryType (a kedvezmény pénzösszege), discountRate =
                // RateType (0-1 közötti arány, ugyanaz a formátum, mint a vatPercentage-nál)
                // — a NAV séma két KÜLÖN mezőt vár, nem egy "10.00%" stringet a
                // discountValue-ban (ez volt az eredeti hiba). net_amount már a kedvezmény
                // UTÁNI érték (l. InvoiceService::create()), ezért a kedvezmény pénzben
                // kifejezett összege quantity×unitPrice − net_amount.
                $discountAmount = ((float) $item->quantity * (float) $item->unit_price) - (float) $item->net_amount;

                $lineDiscount = $line->addChild('lineDiscountData');
                $lineDiscount->addChild('discountDescription', 'Engedmény');
                $lineDiscount->addChild('discountValue', $this->fmt($discountAmount));
                $lineDiscount->addChild('discountRate', number_format((float) $item->discount_percent / 100, 4, '.', ''));
            }

            $lineAmounts = $line->addChild('lineAmountsNormal');

            $lineNetAmountData = $lineAmounts->addChild('lineNetAmountData');
            $lineNetAmountData->addChild('lineNetAmount', $this->fmt($item->net_amount));
            $lineNetAmountData->addChild('lineNetAmountHUF', $this->fmtHuf($item->net_amount, $exchangeRate, $isHuf));

            // lineVatRate a LineAmountsNormalType MÁSODIK gyermeke — NEM a <line>
            // önálló, közvetlen gyermeke (ez volt az eredeti sorrendhiba).
            $lineVatRate = $lineAmounts->addChild('lineVatRate');
            $this->addVatRate($lineVatRate, $item->vatRate);

            $lineVatData = $lineAmounts->addChild('lineVatData');
            $lineVatData->addChild('lineVatAmount', $this->fmt($item->vat_amount));
            $lineVatData->addChild('lineVatAmountHUF', $this->fmtHuf($item->vat_amount, $exchangeRate, $isHuf));

            $lineGrossAmountData = $lineAmounts->addChild('lineGrossAmountData');
            $lineGrossAmountData->addChild('lineGrossAmountNormal', $this->fmt($item->gross_amount));
            $lineGrossAmountData->addChild('lineGrossAmountNormalHUF', $this->fmtHuf($item->gross_amount, $exchangeRate, $isHuf));
        }
    }

    private function addInvoiceSummary(SimpleXMLElement $parent, Invoice $invoice): void
    {
        $exchangeRate = (float) $invoice->exchange_rate;
        $isHuf = ($invoice->currency === 'HUF');

        $summary = $parent->addChild('invoiceSummary');
        $summaryNormal = $summary->addChild('summaryNormal');

        $byVatRate = $invoice->items->groupBy(fn (InvoiceItem $i) => $i->vat_rate_id);

        foreach ($byVatRate as $vatRateId => $items) {
            $vatRate = $items->first()->vatRate;
            $netSum = $items->sum('net_amount');
            $vatSum = $items->sum('vat_amount');
            $grossSum = $items->sum('gross_amount');

            $summaryByVatRate = $summaryNormal->addChild('summaryByVatRate');

            $vatRateEl = $summaryByVatRate->addChild('vatRate');
            $this->addVatRate($vatRateEl, $vatRate);

            $vatRateNetData = $summaryByVatRate->addChild('vatRateNetData');
            $vatRateNetData->addChild('vatRateNetAmount', $this->fmt($netSum));
            $vatRateNetData->addChild('vatRateNetAmountHUF', $this->fmtHuf($netSum, $exchangeRate, $isHuf));

            $vatRateVatData = $summaryByVatRate->addChild('vatRateVatData');
            $vatRateVatData->addChild('vatRateVatAmount', $this->fmt($vatSum));
            $vatRateVatData->addChild('vatRateVatAmountHUF', $this->fmtHuf($vatSum, $exchangeRate, $isHuf));

            $vatRateGrossData = $summaryByVatRate->addChild('vatRateGrossData');
            $vatRateGrossData->addChild('vatRateGrossAmount', $this->fmt($grossSum));
            $vatRateGrossData->addChild('vatRateGrossAmountHUF', $this->fmtHuf($grossSum, $exchangeRate, $isHuf));
        }

        // invoiceNetAmount/invoiceVatAmount a SummaryNormalType KÖZVETLEN gyermekei
        // (a summaryByVatRate sorok UTÁN) — NEM egy külön summaryGrossData wrapperben,
        // ez volt az eredeti hiba. A summaryGrossData egy ÖNÁLLÓ, a summaryNormal-lal
        // TESTVÉR elem (l. lent), ami kizárólag a bruttó összeget hordozza.
        $summaryNormal->addChild('invoiceNetAmount', $this->fmt($invoice->net_total));
        $summaryNormal->addChild('invoiceNetAmountHUF', $this->fmtHuf($invoice->net_total, $exchangeRate, $isHuf));
        $summaryNormal->addChild('invoiceVatAmount', $this->fmt($invoice->vat_total));
        $summaryNormal->addChild('invoiceVatAmountHUF', $this->fmtHuf($invoice->vat_total, $exchangeRate, $isHuf));

        $summaryGrossData = $summary->addChild('summaryGrossData');
        $summaryGrossData->addChild('invoiceGrossAmount', $this->fmt($invoice->gross_total));
        $summaryGrossData->addChild('invoiceGrossAmountHUF', $this->fmtHuf($invoice->gross_total, $exchangeRate, $isHuf));
    }

    private function addVatRate(SimpleXMLElement $parent, ?VatRate $vatRate): void
    {
        if ($vatRate === null) {
            $parent->addChild('vatOutOfScope');
            return;
        }

        if ($vatRate->rate_percent !== null) {
            $parent->addChild('vatPercentage', number_format((float) $vatRate->rate_percent / 100, 4, '.', ''));
        } else {
            // VatExemption — NAV XSD case values (AAM, TAM, KBAET, KBAUK, EAM, NAM,
            // EUFAD37, EUFADE, EUE, HO, ATK…). Using the nav_code from vat_rates
            // directly as the case value. VERIFY against current invoiceData.xsd
            // on the NAV test environment before production use.
            $vatExemption = $parent->addChild('vatExemption');
            $vatExemption->addChild('case', $vatRate->nav_code);
            $vatExemption->addChild('reason', htmlspecialchars($vatRate->name));
        }
    }

    private function addTaxNumber(SimpleXMLElement $parent, string $elementName, string $taxNumber): void
    {
        $el = $parent->addChild($elementName);
        $this->addTaxNumberFields($el, $taxNumber);
    }

    private function addTaxNumberFields(SimpleXMLElement $parent, string $taxNumber): void
    {
        $parts = explode('-', preg_replace('/\s/', '', $taxNumber));
        $parent->addChild('base:taxpayerId', $parts[0] ?? '', self::NS_BASE);
        if (isset($parts[1])) {
            $parent->addChild('base:vatCode', $parts[1], self::NS_BASE);
        }
        if (isset($parts[2])) {
            $parent->addChild('base:countyCode', $parts[2], self::NS_BASE);
        }
    }

    private function addSimpleAddress(SimpleXMLElement $parent, string $postalCode, string $city, string $addressLine, string $countryCode = 'HU'): void
    {
        $simpleAddress = $parent->addChild('base:simpleAddress', '', self::NS_BASE);
        $simpleAddress->addChild('base:countryCode', $countryCode, self::NS_BASE);
        $simpleAddress->addChild('base:postalCode', $postalCode, self::NS_BASE);
        $simpleAddress->addChild('base:city', htmlspecialchars($city), self::NS_BASE);
        $simpleAddress->addChild('base:additionalAddressDetail', htmlspecialchars($addressLine), self::NS_BASE);
    }

    private function resolveCustomerVatStatus(mixed $partner): string
    {
        if ($partner === null) {
            return 'PRIVATE_PERSON';
        }
        if ($partner->tax_number) {
            return 'DOMESTIC';
        }
        if ($partner->eu_tax_number) {
            return 'OTHER';
        }
        return 'PRIVATE_PERSON';
    }

    private function mapPaymentMethod(?string $code): string
    {
        return self::PAYMENT_METHOD_MAP[$code] ?? 'OTHER';
    }

    private function mapUnit(string $unit): string
    {
        return self::UNIT_MAP[$unit] ?? 'OWN';
    }

    private function fmt(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function fmtHuf(mixed $amount, float $exchangeRate, bool $alreadyHuf): string
    {
        $huf = $alreadyHuf ? (float) $amount : round((float) $amount * $exchangeRate, 2);
        return number_format($huf, 2, '.', '');
    }
}
