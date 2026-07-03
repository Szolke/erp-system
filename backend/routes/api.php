<?php

use App\Http\Controllers\Api\ActiveCompanyController;
use App\Http\Controllers\Api\CustomFieldDefinitionController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\CompanySimplePayController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\TranslationController;
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

// Fordítások betöltése — publikus, nincs auth (a login oldal is használja)
Route::get('/translations/{locale}', [TranslationController::class, 'forLocale']);

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

    // Cégek kezelése — szuperadmin: lista + létrehozás; aktív cég: show/update/logo
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);

    Route::get('/company', [CompanyController::class, 'show']);
    Route::put('/company', [CompanyController::class, 'update']);
    Route::post('/company/logo', [CompanyController::class, 'uploadLogo']);
    Route::delete('/company/logo', [CompanyController::class, 'deleteLogo']);

    // Cég-beállítások (kulcs-érték, registry-alapú típuscast)
    Route::get('company/settings', [CompanySettingController::class, 'index']);
    Route::put('company/settings/{key}', [CompanySettingController::class, 'update']);
    Route::delete('company/settings/{key}', [CompanySettingController::class, 'destroy']);

    // SimplePay hitelesítő adatok (devizánként, titkosítva tárolva)
    Route::get('company/simplepay', [CompanySimplePayController::class, 'index']);
    Route::put('company/simplepay/{currency}', [CompanySimplePayController::class, 'upsert']);
    Route::delete('company/simplepay/{currency}', [CompanySimplePayController::class, 'destroy']);

    // Egyéni mezők definíciói (cég-szintű, company.manage jog)
    Route::get('custom-fields', [CustomFieldDefinitionController::class, 'index']);
    Route::post('custom-fields', [CustomFieldDefinitionController::class, 'store']);
    Route::put('custom-fields/{definition}', [CustomFieldDefinitionController::class, 'update']);
    Route::delete('custom-fields/{definition}', [CustomFieldDefinitionController::class, 'destroy']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('partners', PartnerController::class);

    // Bizonylatok — egységes lista (számla + nyugta + sztornók)
    Route::get('documents', [DocumentController::class, 'index']);

    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::post('invoices/{invoice}/regenerate-pdf', [InvoiceController::class, 'regeneratePdf']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::get('invoices/{invoice}/payments', [PaymentController::class, 'index']);
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store']);
    Route::post('invoices/{invoice}/simplepay', [SimplePayController::class, 'start']);
    Route::post('invoices/{invoice}/simplepay-refund', [SimplePayController::class, 'refund']);

    Route::apiResource('receipts', ReceiptController::class)->only(['index', 'store', 'show']);
    Route::post('receipts/{receipt}/cancel', [ReceiptController::class, 'cancel']);
    Route::get('receipts/{receipt}/pdf', [ReceiptController::class, 'pdf']);
    Route::post('receipts/{receipt}/regenerate-pdf', [ReceiptController::class, 'regeneratePdf']);

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

    // Fordítások kezelése (admin) + locale frissítés
    Route::get('translations', [TranslationController::class, 'index']);
    Route::put('translations/{namespace}/{key}', [TranslationController::class, 'upsert']);
    Route::put('me/locale', [TranslationController::class, 'updateLocale']);
});
