<?php

namespace App\Http\Controllers\Api;

use App\Enums\SimplePayStatus;
use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\SimplepayTransaction;
use App\Services\InvoiceService;
use App\Services\SimplePay\SimplePayClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** @group SimplePay fizetés */
class SimplePayController extends Controller
{
    use EnforcesCompanyScope;

    public function start(Invoice $invoice, SimplePayClient $client)
    {
        $this->assertBelongsToCurrentCompany($invoice);
        $this->authorize('payment.create');

        $invoice->loadMissing('partner');

        $orderRef = $invoice->invoice_number.'-'.Str::random(8);
        $returnUrl = rtrim(config('app.frontend_url'), '/').'/invoices/'.$invoice->id.'/payment-result';

        $transaction = SimplepayTransaction::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'order_ref' => $orderRef,
            'amount' => $invoice->gross_total,
            'currency' => $invoice->currency,
            'status' => SimplePayStatus::Started,
        ]);

        $result = $client->startPayment(
            orderRef: $orderRef,
            total: (float) $invoice->gross_total,
            currency: $invoice->currency,
            customerEmail: $invoice->partner?->email ?? 'no-reply@example.com',
            returnUrl: $returnUrl,
        );

        $transaction->update([
            'transaction_id' => $result['response']['transactionId'] ?? null,
            'status' => SimplePayStatus::InProgress,
        ]);

        return response()->json([
            'payment_url' => $result['response']['paymentUrl'] ?? null,
            'order_ref' => $orderRef,
        ]);
    }

    /**
     * Initiate a SimplePay refund and trigger the invoice storno chain.
     *
     * Refunds are synchronous: if SimplePay accepts the request, we
     * immediately mark the transaction as refunded and cancel the invoice.
     * No IPN is expected for refunds (VERIFY against sandbox before production).
     */
    public function refund(Invoice $invoice, SimplePayClient $client, InvoiceService $invoiceService)
    {
        $this->assertBelongsToCurrentCompany($invoice);
        $this->authorize('invoice.cancel');

        $transaction = $invoice->simplepayTransactions()
            ->where('status', SimplePayStatus::Success)
            ->whereNull('refunded_at')
            ->latest()
            ->firstOrFail();

        $result = $client->refund(
            orderRef: $transaction->order_ref,
            transactionId: $transaction->transaction_id,
            refundTotal: (float) $transaction->amount,
            currency: $transaction->currency,
        );

        $transaction->update([
            'status' => SimplePayStatus::Refunded,
            'refund_transaction_id' => $result['refundTransactionId'],
            'refund_amount' => $transaction->amount,
            'refunded_at' => now(),
        ]);

        $storno = $invoiceService->cancel($invoice, Auth::user());

        return response()->json([
            'message' => 'Visszatérítés sikeres. Sztornó számla létrehozva.',
            'storno_invoice' => new InvoiceResource($storno),
        ]);
    }
}
