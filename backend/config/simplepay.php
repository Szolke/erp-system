<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SimplePay (OTP Mobil) — merchant credentials
    |--------------------------------------------------------------------------
    |
    | Single global merchant account (unlike NAV, the functional spec does not
    | describe SimplePay credentials as per-company). Get real values from the
    | SimplePay Partner Portal before going live.
    |
    */

    'merchant_id' => env('SIMPLEPAY_MERCHANT_ID', ''),
    'secret_key' => env('SIMPLEPAY_SECRET_KEY', ''),

    // sandbox|production — defaults to sandbox so a misconfigured env never
    // accidentally charges a real card.
    'environment' => env('SIMPLEPAY_ENVIRONMENT', 'sandbox'),

    'api_urls' => [
        'sandbox' => 'https://sandbox.simplepay.hu/payment/v2/start',
        'production' => 'https://secure.simplepay.hu/payment/v2/start',
    ],

    // VERIFY BEFORE PRODUCTION: refund endpoint URLs per SimplePay v2 docs
    'refund_urls' => [
        'sandbox' => 'https://sandbox.simplepay.hu/payment/v2/refund',
        'production' => 'https://secure.simplepay.hu/payment/v2/refund',
    ],

    'sdk_version' => 'erp-system_simplepay_v2_1.0.0',

    'timeout_minutes' => env('SIMPLEPAY_TIMEOUT_MINUTES', 30),

];
