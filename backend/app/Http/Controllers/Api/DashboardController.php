<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\ModuleResolver;
use App\Services\DashboardService;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @group Dashboard */
class DashboardController extends Controller
{
    /**
     * Az invoice.view jogtól függő négy widget kulcslistája — EGYETLEN forrás:
     * az invoiceWidgets() ezekkel a kulcsokkal építi fel az elérhető widgeteket,
     * a hiányzó-jog ág innen vezeti le a "nincs adat" változatot. Ha egy widget
     * bővül/törlődik, csak itt és az invoiceWidgets()-ben kell módosítani.
     */
    private const INVOICE_WIDGET_KEYS = ['unpaid_invoices', 'overdue_invoices', 'monthly_revenue', 'oldest_unpaid'];

    public function __construct(private readonly DashboardService $service) {}

    /**
     * GET /api/dashboard — a bejelentkezés utáni nyitólap widgetjeinek adatai.
     *
     * A gating teljes egészében itt dől el, a DashboardService nem tud
     * jogosultságról/modulokról. Ha egy widget nem elérhető, a hozzá tartozó
     * service-hívás MEG SE TÖRTÉNIK — nem utólagos szűrés a válaszban.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = Company::findOrFail(app(CurrentCompany::class)->id());

        $widgets = $user->can('invoice.view')
            ? $this->invoiceWidgets($company)
            : array_fill_keys(self::INVOICE_WIDGET_KEYS, ['available' => false, 'reason' => 'no_permission']);

        // A nav_status widgetnél KÉT külön ellenőrzés kell, ebben a sorrendben:
        // (1) modul-állapot közvetlenül a ModuleResolveren, (2) csak utána can().
        // Ok: a Gate::before (AppServiceProvider::boot()) a modul-tiltást és a
        // jog-hiányt EGYETLEN booleanbe süti össze — can('nav.view_log') önmagában
        // nem tudná megkülönböztetni a két okot (a modul-check ott mindig a
        // superadmin-ág ELŐTT fut, l. AppServiceProvider komment). Ha ezt a
        // sorrendet valaki később egy önálló `if ($user->can('nav.view_log'))`-ra
        // egyszerűsítené, a reason csendben rossz lenne (module_disabled helyett
        // mindig no_permission jönne vissza) — ez a hiba a válaszból önmagában
        // nem derülne ki, ezért marad a két lépés explicit, ebben a sorrendben.
        $navEnabled = in_array('nav', app(ModuleResolver::class)->enabledModuleKeys($company->id), true);

        if (! $navEnabled) {
            $widgets['nav_status'] = ['available' => false, 'reason' => 'module_disabled'];
        } elseif (! $user->can('nav.view_log')) {
            $widgets['nav_status'] = ['available' => false, 'reason' => 'no_permission'];
        } else {
            // Nincs 'link' mező: a nav_submission_logs táblához (egyelőre) sem backend
            // végpont, sem frontend oldal nem tartozik — ez az egyetlen kivétel az
            // "minden elérhető widget kap linket" szabály alól, amíg ez nem épül meg.
            $widgets['nav_status'] = ['available' => true, ...$this->service->navStatus($company)];
        }

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'widgets' => $widgets,
        ]);
    }

    /**
     * A négy invoice.view-hoz kötött widget — csak akkor hívódik, ha a jog megvan.
     */
    private function invoiceWidgets(Company $company): array
    {
        $overdue = $this->service->overdueTotals($company);
        $monthly = $this->service->monthlyRevenue($company);

        return [
            'unpaid_invoices' => [
                'available' => true,
                'totals' => $this->service->unpaidTotals($company),
                'link' => ['route' => 'invoices.index', 'params' => ['status' => 'unpaid']],
            ],
            'overdue_invoices' => [
                'available' => true,
                'totals' => $overdue['totals'],
                'oldest_days_overdue' => $overdue['oldest_days_overdue'],
                'link' => ['route' => 'invoices.index', 'params' => ['status' => 'overdue']],
            ],
            'monthly_revenue' => [
                'available' => true,
                'period' => $monthly['period'],
                'totals' => $monthly['totals'],
                'link' => ['route' => 'invoices.index', 'params' => ['issued_from' => $monthly['period']['from']]],
            ],
            'oldest_unpaid' => [
                'available' => true,
                'items' => $this->service->oldestUnpaid($company),
                'link' => ['route' => 'invoices.index', 'params' => ['status' => 'unpaid', 'sort' => 'due_date']],
            ],
        ];
    }
}
