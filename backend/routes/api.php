<?php

use App\Http\Controllers\Api\ActiveCompanyController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentSeriesController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\SimplePayController;
use App\Http\Controllers\Api\SimplePayIpnController;
use App\Http\Controllers\Api\UserController;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\VatRate;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// Public — called directly by SimplePay's servers, authenticated via the
// HMAC Signature header instead of Sanctum (see SimplePayIpnController).
Route::post('/simplepay/ipn', [SimplePayIpnController::class, 'handle']);

Route::middleware(['auth:sanctum', 'company.context'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // Global catalog lookups (no write, no tenant scoping needed)
    Route::get('/vat-rates', fn () => response()->json(['data' => VatRate::where('is_active', true)->get()]));
    Route::get('/payment-methods', fn () => response()->json(['data' => PaymentMethod::where('is_active', true)->get()]));
    Route::put('/active-company', [ActiveCompanyController::class, 'update']);

    Route::get('/company', [CompanyController::class, 'show']);
    Route::put('/company', [CompanyController::class, 'update']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('partners', PartnerController::class);

    // Bizonylatok — egységes lista (számla + nyugta + sztornók)
    Route::get('documents', [DocumentController::class, 'index']);

    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::get('invoices/{invoice}/payments', [PaymentController::class, 'index']);
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store']);
    Route::post('invoices/{invoice}/simplepay', [SimplePayController::class, 'start']);

    Route::apiResource('receipts', ReceiptController::class)->only(['index', 'store', 'show']);
    Route::post('receipts/{receipt}/cancel', [ReceiptController::class, 'cancel']);

    // Felhasználók
    Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::put('users/{user}/overrides', [UserController::class, 'syncOverrides']);

    // Csoportok
    Route::apiResource('groups', GroupController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::put('groups/{group}/permissions', [GroupController::class, 'syncPermissions']);
    Route::post('groups/{group}/members', [GroupController::class, 'addMember']);
    Route::delete('groups/{group}/members/{user}', [GroupController::class, 'removeMember']);

    // Jogosultságok katalógusa
    Route::get('/permissions', fn () => response()->json(['data' => Permission::orderBy('module')->orderBy('key')->get()]));

    // Beállítások — bizonylat-sorszámtartományok
    Route::get('settings/document-series', [DocumentSeriesController::class, 'index']);
    Route::put('settings/document-series/{documentSeries}', [DocumentSeriesController::class, 'update']);
});
