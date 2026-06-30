<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendInvoiceToNavJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private InvoiceNumberGenerator $numberGenerator,
        private AuditLogger $auditLogger,
    ) {}

    public function create(Company $company, array $data, User $creator): Invoice
    {
        return DB::transaction(function () use ($company, $data, $creator) {
            [$series, $invoiceNumber] = $this->numberGenerator->next(
                $company->id, DocumentType::Invoice
            );

            $exchangeRate = $data['currency'] === $company->base_currency
                ? 1.0
                : (float) $data['exchange_rate'];

            $invoice = Invoice::create([
                'company_id' => $company->id,
                'partner_id' => $data['partner_id'],
                'document_series_id' => $series->id,
                'invoice_number' => $invoiceNumber,
                'issue_date' => $data['issue_date'],
                'fulfillment_date' => $data['fulfillment_date'],
                'due_date' => $data['due_date'],
                'currency' => $data['currency'],
                'exchange_rate' => $exchangeRate,
                'exchange_rate_date' => $data['issue_date'],
                'payment_method_id' => $data['payment_method_id'],
                'status' => InvoiceStatus::Issued,
                'payment_status' => PaymentStatus::Open,
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
            ]);

            $this->createItems($invoice, $data['items']);
            $this->updateTotals($invoice, $exchangeRate);

            $loaded = $invoice->refresh()->load(['items.vatRate', 'items.product', 'partner', 'paymentMethod']);

            $this->auditLogger->log('invoice.create', $company->id, $creator->id, $invoice, null, [
                'invoice_number' => $invoice->invoice_number,
                'partner_id' => $invoice->partner_id,
                'gross_total' => $invoice->gross_total,
                'currency' => $invoice->currency,
            ]);

            SendInvoiceToNavJob::dispatch($invoice->id, 'CREATE');

            return $loaded;
        });
    }

    /**
     * Creates a storno (credit note) invoice that cancels the given invoice.
     *
     * The storno is a new, fully-sequenced invoice (same series, next number)
     * with all item quantities and amounts negated. The original invoice is left
     * untouched — its relationship to the storno is visible via Invoice::stornos().
     */
    public function cancel(Invoice $invoice, User $actor): Invoice
    {
        if ($invoice->status === InvoiceStatus::Storno) {
            throw ValidationException::withMessages(['invoice' => ['Egy sztornó számla nem sztornózható.']]);
        }

        if ($invoice->stornos()->exists()) {
            throw ValidationException::withMessages(['invoice' => ['A számla már sztornózva van.']]);
        }

        return DB::transaction(function () use ($invoice, $actor) {
            $invoice->load('items');

            [$series, $invoiceNumber] = $this->numberGenerator->next(
                $invoice->company_id, DocumentType::Invoice
            );

            $storno = Invoice::create([
                'company_id' => $invoice->company_id,
                'partner_id' => $invoice->partner_id,
                'document_series_id' => $series->id,
                'invoice_number' => $invoiceNumber,
                'issue_date' => now()->toDateString(),
                'fulfillment_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'currency' => $invoice->currency,
                'exchange_rate' => $invoice->exchange_rate,
                'exchange_rate_date' => $invoice->exchange_rate_date,
                'payment_method_id' => $invoice->payment_method_id,
                'status' => InvoiceStatus::Storno,
                'payment_status' => PaymentStatus::Open,
                'storno_of_invoice_id' => $invoice->id,
                'notes' => 'Sztornó: '.$invoice->invoice_number,
                'created_by' => $actor->id,
            ]);

            foreach ($invoice->items as $index => $item) {
                $storno->items()->create([
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => -$item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'vat_rate_id' => $item->vat_rate_id,
                    'discount_percent' => $item->discount_percent,
                    'net_amount' => -$item->net_amount,
                    'vat_amount' => -$item->vat_amount,
                    'gross_amount' => -$item->gross_amount,
                    'sort_order' => $index,
                ]);
            }

            $this->updateTotals($storno, (float) $storno->exchange_rate);

            $loaded = $storno->refresh()->load(['items.vatRate', 'items.product', 'partner', 'paymentMethod']);

            $this->auditLogger->log('invoice.cancel', $invoice->company_id, $actor->id, $invoice, [
                'invoice_number' => $invoice->invoice_number,
            ], [
                'storno_invoice_number' => $storno->invoice_number,
            ]);

            SendInvoiceToNavJob::dispatch($storno->id, 'STORNO');

            return $loaded;
        });
    }

    private function createItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $index => $item) {
            $vatRate = VatRate::findOrFail($item['vat_rate_id']);
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $discountPercent = (float) ($item['discount_percent'] ?? 0);

            $netAmount = round($quantity * $unitPrice * (1 - $discountPercent / 100), 2);
            $vatAmount = round($netAmount * (float) ($vatRate->rate_percent ?? 0) / 100, 2);

            $invoice->items()->create([
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit' => $item['unit'],
                'unit_price' => $unitPrice,
                'vat_rate_id' => $vatRate->id,
                'discount_percent' => $discountPercent ?: null,
                'net_amount' => $netAmount,
                'vat_amount' => $vatAmount,
                'gross_amount' => $netAmount + $vatAmount,
                'sort_order' => $index,
            ]);
        }
    }

    private function updateTotals(Invoice $invoice, float $exchangeRate): void
    {
        $invoice->loadMissing('items');

        $netTotal = (float) $invoice->items->sum('net_amount');
        $vatTotal = (float) $invoice->items->sum('vat_amount');
        $grossTotal = round($netTotal + $vatTotal, 2);

        $invoice->update([
            'net_total' => $netTotal,
            'vat_total' => $vatTotal,
            'gross_total' => $grossTotal,
            'gross_total_base_currency' => round($grossTotal * $exchangeRate, 2),
        ]);
    }
}
