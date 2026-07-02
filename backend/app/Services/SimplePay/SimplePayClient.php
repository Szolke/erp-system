<?php

namespace App\Services\SimplePay;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SimplePay (OTP Mobil) v2 API client — signs and sends the "start payment"
 * request and verifies/builds IPN responses.
 *
 * Built directly against Laravel's HTTP client (no third-party SDK): the
 * official PHP SDK (github.com/IconoCoders/otp-simple-sdk) hasn't been
 * updated since 2021, and the field/endpoint details below were instead
 * confirmed against that SDK's source and real-world IPN examples.
 *
 * VERIFY BEFORE PRODUCTION USE (genuinely uncertain, flagged per CLAUDE.md):
 * the exact `url` callback field structure (this implementation uses a
 * single `url` field; SimplePay may expect separate success/fail/cancel/
 * timeout URLs) and the `total` decimal formatting. Test against
 * sandbox.simplepay.hu with real sandbox merchant credentials first.
 */
class SimplePayClient
{
    public function startPayment(string $orderRef, float $total, string $currency, string $customerEmail, string $returnUrl): array
    {
        $salt = Str::random(32);

        $payload = [
            'salt' => $salt,
            'merchant' => config('simplepay.merchant_id'),
            'orderRef' => $orderRef,
            'currency' => strtoupper($currency),
            'customerEmail' => $customerEmail,
            'language' => 'HU',
            'sdkVersion' => config('simplepay.sdk_version'),
            'methods' => ['CARD'],
            'total' => $this->formatTotal($total, $currency),
            'timeout' => now()->addMinutes((int) config('simplepay.timeout_minutes'))->format('Y-m-d\TH:i:sO'),
            'url' => $returnUrl,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = $this->sign($body);

        $apiUrl = config('simplepay.api_urls.'.config('simplepay.environment'));

        $response = Http::withHeaders(['Signature' => $signature])
            ->withBody($body, 'application/json')
            ->post($apiUrl);

        if ($response->failed()) {
            throw new RuntimeException('SimplePay start hívás sikertelen: HTTP '.$response->status().' '.$response->body());
        }

        return [
            'salt' => $salt,
            'request' => $payload,
            'response' => $response->json(),
        ];
    }

    /**
     * Initiates a refund for a previously completed transaction.
     *
     * VERIFY BEFORE PRODUCTION: the exact request body field names
     * (`refundTotal` vs `total`, `transactionId` presence) and the
     * response structure must be confirmed against the SimplePay v2
     * sandbox with real merchant credentials.
     *
     * @return array{refundTransactionId: string|null, status: string}
     */
    public function refund(string $orderRef, string $transactionId, float $refundTotal, string $currency): array
    {
        $salt = Str::random(32);

        $payload = [
            'salt' => $salt,
            'merchant' => config('simplepay.merchant_id'),
            'orderRef' => $orderRef,
            'transactionId' => $transactionId,
            'currency' => strtoupper($currency),
            'refundTotal' => $this->formatTotal($refundTotal, $currency),
            'sdkVersion' => config('simplepay.sdk_version'),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = $this->sign($body);

        $apiUrl = config('simplepay.refund_urls.'.config('simplepay.environment'));

        $response = Http::withHeaders(['Signature' => $signature])
            ->withBody($body, 'application/json')
            ->post($apiUrl);

        if ($response->failed()) {
            throw new RuntimeException('SimplePay visszatérítés sikertelen: HTTP '.$response->status().' '.$response->body());
        }

        $data = $response->json();

        // Treat any non-success errorCode as a failure
        if (! empty($data['errorCode']) && $data['errorCode'] !== 'SUCCESS') {
            throw new RuntimeException('SimplePay visszatérítés hiba: '.($data['errorMessage'] ?? $data['errorCode']));
        }

        return [
            'refundTransactionId' => $data['refundTransactionId'] ?? null,
            'status' => $data['status'] ?? 'UNKNOWN',
        ];
    }

    public function verifyIpnSignature(string $rawBody, ?string $signatureHeader): bool
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        return hash_equals($this->sign($rawBody), $signatureHeader);
    }

    /**
     * Builds the signed acknowledgement SimplePay expects back from the IPN
     * endpoint: the original payload plus a receiveDate, re-signed.
     *
     * @return array{body: string, signature: string}
     */
    public function buildIpnAcknowledgement(array $decodedPayload): array
    {
        $decodedPayload['receiveDate'] = now()->format('c');

        $body = json_encode($decodedPayload, JSON_UNESCAPED_SLASHES);

        return [
            'body' => $body,
            'signature' => $this->sign($body),
        ];
    }

    private function sign(string $data): string
    {
        return base64_encode(hash_hmac('sha384', $data, config('simplepay.secret_key'), true));
    }

    private function formatTotal(float $amount, string $currency): string
    {
        // HUF has no circulating subunit; other currencies use 2 decimals.
        $decimals = strtoupper($currency) === 'HUF' ? 0 : 2;

        return number_format($amount, $decimals, '.', '');
    }
}
