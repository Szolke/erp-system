<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\ReceiptStatus;
use App\Models\Company;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors InvoiceService's create/cancel pattern for the simplified
 * "nyugta" document type (no NAV submission, partner optional).
 */
class ReceiptService
{
    public function __construct(private InvoiceNumberGenerator $numberGenerator) {}

    public function create(Company $company, array $data, User $creator): Receipt
    {
        return DB::transaction(function () use ($company, $data, $creator) {
            [$series, $receiptNumber] = $this->numberGenerator->next(
                $company->id, DocumentType::Receipt, 'NY'
            );

            $exchangeRate = $data['currency'] === $company->base_currency
                ? 1.0
                : (float) $data['exchange_rate'];

            $receipt = Receipt::create([
                'company_id' => $company->id,
                'partner_id' => $data['partner_id'] ?? null,
                'document_series_id' => $series->id,
                'receipt_number' => $receiptNumber,
                'issue_date' => $data['issue_date'],
                'currency' => $data['currency'],
                'exchange_rate' => $exchangeRate,
                'exchange_rate_date' => $data['issue_date'],
                'payment_method_id' => $data['payment_method_id'],
                'status' => ReceiptStatus::Issued,
                'created_by' => $creator->id,
            ]);

            $this->createItems($receipt, $data['items']);
            $this->updateTotals($receipt);

            return $receipt->refresh()->load(['items.vatRate', 'items.product', 'partner', 'paymentMethod']);
        });
    }

    public function cancel(Receipt $receipt): Receipt
    {
        if ($receipt->status === ReceiptStatus::Storno) {
            throw ValidationException::withMessages(['receipt' => ['Egy sztornó nyugta nem sztornózható.']]);
        }

        if ($receipt->stornos()->exists()) {
            throw ValidationException::withMessages(['receipt' => ['A nyugta már sztornózva van.']]);
        }

        return DB::transaction(function () use ($receipt) {
            $receipt->load('items');

            [$series, $receiptNumber] = $this->numberGenerator->next(
                $receipt->company_id, DocumentType::Receipt, 'NY'
            );

            $storno = Receipt::create([
                'company_id' => $receipt->company_id,
                'partner_id' => $receipt->partner_id,
                'document_series_id' => $series->id,
                'receipt_number' => $receiptNumber,
                'issue_date' => now()->toDateString(),
                'currency' => $receipt->currency,
                'exchange_rate' => $receipt->exchange_rate,
                'exchange_rate_date' => $receipt->exchange_rate_date,
                'payment_method_id' => $receipt->payment_method_id,
                'status' => ReceiptStatus::Storno,
                'storno_of_receipt_id' => $receipt->id,
            ]);

            foreach ($receipt->items as $index => $item) {
                $storno->items()->create([
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => -$item->quantity,
                    'unit_price' => $item->unit_price,
                    'vat_rate_id' => $item->vat_rate_id,
                    'net_amount' => -$item->net_amount,
                    'vat_amount' => -$item->vat_amount,
                    'gross_amount' => -$item->gross_amount,
                    'sort_order' => $index,
                ]);
            }

            $this->updateTotals($storno);

            return $storno->refresh()->load(['items.vatRate', 'items.product', 'partner', 'paymentMethod']);
        });
    }

    private function createItems(Receipt $receipt, array $items): void
    {
        foreach ($items as $index => $item) {
            $vatRate = VatRate::findOrFail($item['vat_rate_id']);
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];

            $netAmount = round($quantity * $unitPrice, 2);
            $vatAmount = round($netAmount * (float) ($vatRate->rate_percent ?? 0) / 100, 2);

            $receipt->items()->create([
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'vat_rate_id' => $vatRate->id,
                'net_amount' => $netAmount,
                'vat_amount' => $vatAmount,
                'gross_amount' => $netAmount + $vatAmount,
                'sort_order' => $index,
            ]);
        }
    }

    private function updateTotals(Receipt $receipt): void
    {
        $receipt->loadMissing('items');

        $netTotal = (float) $receipt->items->sum('net_amount');
        $vatTotal = (float) $receipt->items->sum('vat_amount');

        $receipt->update([
            'net_total' => $netTotal,
            'vat_total' => $vatTotal,
            'gross_total' => round($netTotal + $vatTotal, 2),
        ]);
    }
}
