<?php

use App\Http\Controllers\Api\ActiveCompanyController;
use App\Http\Controllers\Api\AdminSalesGroupController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AssetTypeController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\ApiTesterController;
use App\Http\Controllers\Api\CustomFieldDefinitionController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\CompanyNavCredentialController;
use App\Http\Controllers\Api\CompanySimplePayController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\DocumentSeriesController;
use App\Http\Controllers\Api\EnyugtaReportController;
use App\Http\Controllers\Api\EnyugtaSettingsController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JobPositionController;
use App\Http\Controllers\Api\ListPreferenceController;
use App\Http\Controllers\Api\NavSubmissionLogController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\SalesGroupController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SimplePayController;
use App\Http\Controllers\Api\SimplePayIpnController;
use App\Http\Controllers\Api\TokenAuthController;
use App\Http\Controllers\Api\UserCompanyController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPasswordController;
use App\Http\Controllers\Api\UserTokenController;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\VatRate;
use App\Services\PermissionChecker;
use Illuminate\Support\Facades\Route;

// Fordítások betöltése — publikus, nincs auth (a login oldal is használja)
Route::get('/translations/{locale}', [TranslationController::class, 'forLocale']);

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// Token-auth (külső kliensek: mobil, API-integrációk)
// company.context NEM kell ide — a token-login nem igényel cég-kontextust,
// a revoke-hoz sem szükséges (nincs cég-szintű adat-hozzáférés).
Route::post('/auth/token', [TokenAuthController::class, 'issue'])
    ->middleware('throttle:5,1');
Route::post('/auth/token/revoke', [TokenAuthController::class, 'revoke'])
    ->middleware('auth:sanctum');

// Public — called directly by SimplePay's servers, authenticated via the
// HMAC Signature header instead of Sanctum (see SimplePayIpnController).
Route::post('/simplepay/ipn', [SimplePayIpnController::class, 'handle']);

Route::middleware(['auth:sanctum', 'company.context'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Global catalog lookups (no write, no tenant scoping needed)
    Route::get('/vat-rates', fn () => response()->json(['data' => VatRate::where('is_active', true)->get()]));
    Route::get('/payment-methods', fn () => response()->json(['data' => PaymentMethod::where('is_active', true)->get()]));
    Route::get('/countries', [CountryController::class, 'index']);
    Route::put('/active-company', [ActiveCompanyController::class, 'update']);

    // Modul-katalógus + be/kikapcsolás (superadmin-only, module.manage)
    Route::get('modules', [ModuleController::class, 'index']);
    Route::patch('modules/{module}', [ModuleController::class, 'update']);

    // Országkatalógus kezelése (superadmin-only, globális, nincs company_id)
    Route::get('admin/countries', [CountryController::class, 'adminIndex']);
    Route::put('admin/countries', [CountryController::class, 'adminUpdate']);

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

    // NAV Online Számla hitelesítő adatok (environmentenként, titkosítva tárolva)
    // A literal /active-environment ELŐBB van definiálva, mint a /{environment} param-route.
    Route::middleware('module:nav')->group(function () {
        Route::get('company/nav', [CompanyNavCredentialController::class, 'index']);
        Route::patch('company/nav/active-environment', [CompanyNavCredentialController::class, 'setActiveEnvironment']);
        Route::put('company/nav/{environment}', [CompanyNavCredentialController::class, 'upsert']);
        Route::delete('company/nav/{environment}', [CompanyNavCredentialController::class, 'destroy']);

        Route::get('nav-submissions', [NavSubmissionLogController::class, 'index']);
        Route::get('nav-submissions/{nav_submission_log}', [NavSubmissionLogController::class, 'show']);
    });

    // SimplePay hitelesítő adatok (devizánként, titkosítva tárolva)
    Route::middleware('module:simplepay')->group(function () {
        Route::get('company/simplepay', [CompanySimplePayController::class, 'index']);
        Route::put('company/simplepay/{currency}', [CompanySimplePayController::class, 'upsert']);
        Route::delete('company/simplepay/{currency}', [CompanySimplePayController::class, 'destroy']);
    });

    // Egyéni mezők definíciói (cég-szintű, company.manage jog)
    Route::get('custom-fields', [CustomFieldDefinitionController::class, 'index']);
    Route::post('custom-fields', [CustomFieldDefinitionController::class, 'store']);
    Route::put('custom-fields/{definition}', [CustomFieldDefinitionController::class, 'update']);
    Route::delete('custom-fields/{definition}', [CustomFieldDefinitionController::class, 'destroy']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('partners', PartnerController::class);

    Route::middleware('module:sales_group')->group(function () {
        Route::apiResource('sales-groups', SalesGroupController::class);

        // Tagság (sales_group_user pivot) — cégre scope-olva, sales_group.edit joggal.
        Route::get('sales-groups/{salesGroup}/users', [SalesGroupController::class, 'users']);
        Route::put('sales-groups/{salesGroup}/users', [SalesGroupController::class, 'syncUsers']);

        // Cégek közötti nézet (superadmin, csak olvasás) — nincs route model binding.
        Route::get('admin/sales-groups', [AdminSalesGroupController::class, 'index']);

        // Cégek közötti ÍRÁS (superadmin). A company.cross middleware a kérés
        // idejére a CÉL cégre állítja a CurrentCompany-t — create-nél a törzs
        // company_id mezőjéből, update/delete-nél a bound modellből —, így
        // innentől a megszokott, cégre scope-olt CRUD-gépezet fut. Az
        // útvonal-paraméter neve kötelezően `sales_group`: az
        // UpdateSalesGroupRequest ezen a néven olvassa ki a route-modellt.
        Route::post('admin/sales-groups', [AdminSalesGroupController::class, 'store'])
            ->middleware('company.cross');
        Route::put('admin/sales-groups/{sales_group}', [AdminSalesGroupController::class, 'update'])
            ->middleware('company.cross:sales_group');
        Route::delete('admin/sales-groups/{sales_group}', [AdminSalesGroupController::class, 'destroy'])
            ->middleware('company.cross:sales_group');
    });

    Route::middleware('module:assets')->group(function () {
        Route::apiResource('assets', AssetController::class);
        Route::apiResource('asset-types', AssetTypeController::class)->only(['index', 'store']);
    });

    // Bizonylatok — egységes lista (számla + nyugta + sztornók)
    Route::get('documents', [DocumentController::class, 'index']);
    Route::get('documents/export', [DocumentController::class, 'export']);

    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::post('invoices/{invoice}/regenerate-pdf', [InvoiceController::class, 'regeneratePdf']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::get('invoices/{invoice}/payments', [PaymentController::class, 'index']);
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store']);
    Route::middleware('module:simplepay')->group(function () {
        Route::post('invoices/{invoice}/simplepay', [SimplePayController::class, 'start']);
        Route::post('invoices/{invoice}/simplepay-refund', [SimplePayController::class, 'refund']);
    });
    Route::middleware('module:nav')->group(function () {
        Route::get('invoices/{invoice}/nav-submissions', [NavSubmissionLogController::class, 'forInvoice']);
    });

    Route::apiResource('receipts', ReceiptController::class)->only(['index', 'store', 'show']);
    Route::post('receipts/{receipt}/cancel', [ReceiptController::class, 'cancel']);
    Route::get('receipts/{receipt}/pdf', [ReceiptController::class, 'pdf']);
    Route::post('receipts/{receipt}/regenerate-pdf', [ReceiptController::class, 'regeneratePdf']);

    // Kimutatások (reports) — nincs module:reports middleware, l. ReportController docblock:
    // a jogosultság-feloldás (Gate::before + ModuleResolver) önmagában 403-at ad kikapcsolt
    // modulnál a report.view/report.export kulcsokon keresztül.
    Route::get('reports/invoices', [ReportController::class, 'invoices']);
    Route::get('reports/products', [ReportController::class, 'products']);
    Route::get('reports/receivables-aging', [ReportController::class, 'receivablesAging']);
    Route::get('reports/vat-summary', [ReportController::class, 'vatSummary']);
    Route::get('reports/{report}/export', [ReportController::class, 'export']);

    // Munkakörök (job_position — nem RBAC-szerep, csak user-kezelési törzsadat)
    Route::apiResource('job-positions', JobPositionController::class)->only(['index', 'store', 'update', 'destroy']);

    // Felhasználók
    Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::put('users/{user}/overrides', [UserController::class, 'syncOverrides']);

    // User–cég hozzárendelés (superadmin-only)
    Route::get('users/{user}/companies', [UserCompanyController::class, 'index']);
    Route::post('users/{user}/companies/{company}', [UserCompanyController::class, 'attach']);
    Route::delete('users/{user}/companies/{company}', [UserCompanyController::class, 'detach']);

    // Token-eszközkezelő: superadmin bármely user, normál user csak saját
    Route::put('users/{user}/password', [UserPasswordController::class, 'update']);
    Route::get('users/{user}/tokens', [UserTokenController::class, 'index']);
    Route::delete('users/{user}/tokens/{tokenId}', [UserTokenController::class, 'destroy']);

    // Csoportok
    Route::apiResource('groups', GroupController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::put('groups/{group}/permissions', [GroupController::class, 'syncPermissions']);
    Route::post('groups/{group}/members', [GroupController::class, 'addMember']);
    Route::delete('groups/{group}/members/{user}', [GroupController::class, 'removeMember']);

    // Jogosultságok katalógusa (a csoport-szerkesztő ebből építi a checkbox-listát).
    // A superadmin-only kulcsok kimaradnak: azokat a PermissionChecker normál
    // felhasználónál úgyis kivágja, így csoporthoz rendelve néma no-op lenne.
    Route::get('/permissions', fn () => response()->json([
        'data' => Permission::whereNotIn('key', PermissionChecker::SUPERADMIN_ONLY_KEYS)
            ->orderBy('module')
            ->orderBy('key')
            ->get(),
    ]));

    // Beállítások — bizonylat-sorszámtartományok
    Route::get('settings/document-series', [DocumentSeriesController::class, 'index']);
    Route::put('settings/document-series/{documentSeries}', [DocumentSeriesController::class, 'update']);

    // NAV eNyugta — 1. fázis: beállítások (hitelesítő adat + üzemmód); 2. fázis:
    // napi jelentések olvasása + CSV export. Beküldés NINCS (3. fázis).
    Route::middleware('module:enyugta')->group(function () {
        Route::get('settings/enyugta', [EnyugtaSettingsController::class, 'show']);
        Route::put('settings/enyugta', [EnyugtaSettingsController::class, 'update']);
        Route::post('settings/enyugta/copy-from-nav', [EnyugtaSettingsController::class, 'copyFromNav']);

        Route::get('enyugta/reports', [EnyugtaReportController::class, 'index']);
        Route::get('enyugta/reports/{report}', [EnyugtaReportController::class, 'show']);
        Route::get('enyugta/reports/{report}/export', [EnyugtaReportController::class, 'export']);
    });

    // Lista-preferenciák (oszlopválasztó) — saját erőforrás, nincs külön jog
    Route::put('list-preferences/{listKey}', [ListPreferenceController::class, 'update'])
        ->where('listKey', '[a-z0-9_.]{1,64}');
    Route::delete('list-preferences/{listKey}', [ListPreferenceController::class, 'destroy'])
        ->where('listKey', '[a-z0-9_.]{1,64}');

    // API-tesztelő
    Route::get('api-tester/openapi', [ApiTesterController::class, 'openapi']);

    // Fordítások kezelése (admin) + locale frissítés
    Route::get('translations', [TranslationController::class, 'index']);
    Route::put('translations/{namespace}/{key}', [TranslationController::class, 'upsert']);
    Route::put('me/locale', [TranslationController::class, 'updateLocale']);
});
