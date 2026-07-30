<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cégek közötti (superadmin) ÍRÁSI végpontok cég-kontextusa.
 *
 * Miért van rá szükség? A cross-company írás naiv megoldása egy párhuzamos,
 * withoutGlobalScope-os CRUD-készlet lenne — az viszont megkettőzné a
 * validációt, az audit-logikát és a scope-garanciát is. Helyette itt egyetlen
 * ponton a CÉLERŐFORRÁSBÓL oldjuk fel a CurrentCompany-t, MÉG MIELŐTT a
 * company-scope életbe lépne; onnantól a meglévő, bevált CRUD-gépezet fut
 * változatlanul (FormRequest-validáció, globális scope, BelongsToCompany
 * auto-stamp), csak épp a cél cégre.
 *
 * Használat:
 *   ->middleware('company.cross')                 // create: company_id a kérés törzséből
 *   ->middleware('company.cross:sales_group')     // update/delete: a bound modell company_id-jából
 *
 * ═══ BIZTONSÁGI INVARIÁNS ═══
 * A SubstituteBindings az EnsureCompanyContext (és így ezen middleware) ELŐTT
 * fut, ezért a bound modell cég-szűrés nélkül töltődik be. Mivel a kontextust
 * ezután épp a modellből állítjuk be, az assertBelongsToCurrentCompany() ezeken
 * a végpontokon tautológiává válik — a tenant-védelem EGYETLEN garanciája az
 * alábbi superadmin-kapu. Ezért az MINDEN más művelet ELŐTT, fail-closed módon
 * fut le: nincs bejelentkezett user vagy nem superadmin → azonnali 403.
 *
 * ═══ KÉRÉS-SZINTŰ FELÜLÍRÁS ═══
 * A felülírás szándékosan NEM érinti sem a session `current_company_id`-ját,
 * sem a users.default_company_id-t: a superadmin aktív cége nem ragadhat rá egy
 * idegen cégre egy cross-company művelet után. A korábbi értéket a finally-ág
 * visszaállítja, így hibás (abortált) kérés után sem szivárog tovább.
 */
class ResolveCrossCompanyContext
{
    public function handle(Request $request, Closure $next, ?string $routeParameter = null): Response
    {
        $user = $request->user();

        // Fail-closed superadmin-kapu — mindenek előtt (l. osztálykomment).
        abort_unless(
            (bool) $user?->is_superadmin,
            403,
            'Cégek közötti művelethez szuperadmin jogosultság szükséges.'
        );

        $targetCompanyId = $routeParameter === null
            ? $this->companyIdFromRequest($request)
            : $this->companyIdFromBoundModel($request, $routeParameter);

        $currentCompany    = app(CurrentCompany::class);
        $previousCompanyId = $currentCompany->id();

        $currentCompany->set($targetCompanyId);

        try {
            return $next($request);
        } finally {
            $currentCompany->set($previousCompanyId);
        }
    }

    /**
     * Update/delete: a cél cég a route-model-bindinggel betöltött erőforrásé.
     */
    private function companyIdFromBoundModel(Request $request, string $routeParameter): int
    {
        $model = $request->route($routeParameter);

        // Ha a binding nem futott le (elgépelt útvonal-paraméter), inkább 404,
        // mint néma továbbfutás rossz vagy hiányzó cég-kontextussal.
        if (! $model instanceof Model || $model->company_id === null) {
            abort(404);
        }

        return (int) $model->company_id;
    }

    /**
     * Create: nincs bound erőforrás, ezért a kérés explicit company_id-t hoz.
     * A cél cég létezését itt kell ellenőrizni — a FormRequest szabályai már a
     * beállított kontextusra épülnek, tehát ez a validáció NEM tolható el odáig.
     */
    private function companyIdFromRequest(Request $request): int
    {
        $companyId = $request->input('company_id');

        if (! is_numeric($companyId) || ! Company::whereKey((int) $companyId)->exists()) {
            throw ValidationException::withMessages([
                'company_id' => 'Érvényes cél céget kell megadni.',
            ]);
        }

        return (int) $companyId;
    }
}
