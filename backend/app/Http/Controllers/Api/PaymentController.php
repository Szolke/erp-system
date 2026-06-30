<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Services\PaymentStatusUpdater;

/**
 * Manual payment recording for non-SimplePay methods (cash, card terminal,
 * bank transfer confirmation). SimplePay payments are recorded automatically
 * by SimplePayIpnController instead.
 */
class PaymentController extends Controller
{
    public function index(Invoice $invoice)
    {
        $this->authorize('payment.view');

        return PaymentResource::collection(
            $invoice->payments()->with('paymentMethod')->orderByDesc('paid_at')->get()
        );
    }

    public function store(StorePaymentRequest $request, Invoice $invoice, PaymentStatusUpdater $statusUpdater)
    {
        $payment = $invoice->payments()->create([
            'company_id' => $invoice->company_id,
            'payment_method_id' => $request->validated('payment_method_id'),
            'amount' => $request->validated('amount'),
            'currency' => $invoice->currency,
            'paid_at' => $request->validated('paid_at'),
            'reference' => $request->validated('reference'),
            'created_by' => $request->user()->id,
        ]);

        $statusUpdater->recalculate($invoice);

        return PaymentResource::make($payment->load('paymentMethod'))->response()->setStatusCode(201);
    }
}
