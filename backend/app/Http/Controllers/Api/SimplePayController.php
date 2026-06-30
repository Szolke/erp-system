<?php

namespace App\Http\Controllers\Api;

use App\Enums\SimplePayStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\SimplepayTransaction;
use App\Services\SimplePay\SimplePayClient;
use Illuminate\Support\Str;

class SimplePayController extends Controller
{
    public function start(Invoice $invoice, SimplePayClient $client)
    {
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
}
