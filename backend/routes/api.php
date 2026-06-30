<?php

use App\Http\Controllers\Api\ActiveCompanyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\SimplePayController;
use App\Http\Controllers\Api\SimplePayIpnController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// Public — called directly by SimplePay's servers, authenticated via the
// HMAC Signature header instead of Sanctum (see SimplePayIpnController).
Route::post('/simplepay/ipn', [SimplePayIpnController::class, 'handle']);

Route::middleware(['auth:sanctum', 'company.context'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/active-company', [ActiveCompanyController::class, 'update']);

    Route::get('/company', [CompanyController::class, 'show']);
    Route::put('/company', [CompanyController::class, 'update']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('partners', PartnerController::class);

    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
    Route::get('invoices/{invoice}/payments', [PaymentController::class, 'index']);
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store']);
    Route::post('invoices/{invoice}/simplepay', [SimplePayController::class, 'start']);

    Route::apiResource('receipts', ReceiptController::class)->only(['index', 'store', 'show']);
    Route::post('receipts/{receipt}/cancel', [ReceiptController::class, 'cancel']);
});
