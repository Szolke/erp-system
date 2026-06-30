<?php

namespace App\Http\Controllers\Api;

use App\Enums\SimplePayStatus;
use App\Http\Controllers\Controller;
use App\Models\SimplepayTransaction;
use App\Services\PaymentStatusUpdater;
use App\Services\SimplePay\SimplePayClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Public endpoint — SimplePay's servers POST here directly (no Sanctum
 * session, no CSRF token). Authenticity comes entirely from the HMAC
 * Signature header, verified against the raw request body.
 */
class SimplePayIpnController extends Controller
{
    private const STATUS_MAP = [
        'FINISHED' => SimplePayStatus::Success,
        'TIMEOUT' => SimplePayStatus::Timeout,
        'CANCELLED' => SimplePayStatus::Cancel,
    ];

    public function handle(Request $request, SimplePayClient $client, PaymentStatusUpdater $statusUpdater): Response
    {
        $rawBody = $request->getContent();
        $signature = $request->header('Signature');

        if (! $client->verifyIpnSignature($rawBody, $signature)) {
            Log::warning('SimplePay IPN: invalid signature', ['order_ref' => $request->input('orderRef')]);

            return response('Invalid signature', 400);
        }

        $payload = json_decode($rawBody, true);
        $orderRef = $payload['orderRef'] ?? null;

        $transaction = SimplepayTransaction::query()->where('order_ref', $orderRef)->first();

        if ($transaction === null) {
            Log::warning('SimplePay IPN: unknown order_ref', ['order_ref' => $orderRef]);

            return response('Unknown orderRef', 404);
        }

        $status = self::STATUS_MAP[$payload['status'] ?? ''] ?? SimplePayStatus::Fail;

        $transaction->update([
            'status' => $status,
            'ipn_payload' => $payload,
            'ipn_received_at' => now(),
            'finished_at' => isset($payload['finishDate']) ? now()->parse($payload['finishDate']) : now(),
        ]);

        if ($status === SimplePayStatus::Success) {
            $invoice = $transaction->invoice;

            $invoice->payments()->create([
                'company_id' => $invoice->company_id,
                'payment_method_id' => \App\Models\PaymentMethod::where('code', 'simplepay')->value('id'),
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'paid_at' => $transaction->finished_at,
                'reference' => 'SimplePay tranzakció: '.$transaction->order_ref,
            ]);

            $statusUpdater->recalculate($invoice);
        }

        $ack = $client->buildIpnAcknowledgement($payload);

        return response($ack['body'], 200)
            ->header('Content-Type', 'application/json')
            ->header('Signature', $ack['signature']);
    }
}
