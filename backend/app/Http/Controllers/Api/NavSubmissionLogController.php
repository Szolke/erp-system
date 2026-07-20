<?php

namespace App\Http\Controllers\Api;

use App\Enums\NavStatus;
use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\NavSubmissionLogDetailResource;
use App\Http\Resources\NavSubmissionLogGroupedResource;
use App\Http\Resources\NavSubmissionLogResource;
use App\Models\Invoice;
use App\Models\NavSubmissionLog;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/**
 * @group NAV napló
 *
 * Read-only nézetek a nav_submission_logs táblára — l. docs/nav-logging-audit.md
 * (1-2. fázis: a napló eddig csak íródott, ez a fázis teszi visszaolvashatóvá).
 * A NavSubmissionLog modellen szándékosan NINCS BelongsToCompany trait (l. a
 * modell kommentje) — minden lekérdezés a scopeForCompany()-n vagy explicit
 * assertBelongsToCurrentCompany()-n át megy, sosem globális scope-ra hagyatkozva.
 */
class NavSubmissionLogController extends Controller
{
    use EnforcesCompanyScope;

    /** "Hibás" végállapotok — ez a monitorozható mutató, emiatt készült a napló. */
    private const ERROR_STATUSES = [
        NavStatus::Error->value,
        NavStatus::Rejected->value,
        NavStatus::NeedsAttention->value,
    ];

    /**
     * Egy adott számla NAV-beküldési előzményei, időrendben — nyers XML nélkül.
     * Route model binding az EnsureCompanyContext middleware ELŐTT fut, ezért
     * kötelező az assertBelongsToCurrentCompany().
     */
    public function forInvoice(Invoice $invoice)
    {
        $this->assertBelongsToCurrentCompany($invoice);
        $this->authorize('nav.log.view');

        $logs = NavSubmissionLog::query()
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('created_at')
            ->get();

        return NavSubmissionLogResource::collection($logs);
    }

    /**
     * Cégszintű beküldés-lista — EGY SOR = EGY ÉRINTETT SZÁMLA, nem naplósor. Ez a
     * képernyő arra válaszol, hogy "van-e bárhol baj?" — a válasz problémás SZÁMLÁK
     * halmaza, nem naplósoroké (három hibás kísérlet egy számláról EGY problémát
     * jelent, nem hármat). Soronként a számla adatai + a LEGUTOLSÓ naplóbejegyzés +
     * a hozzá tartozó kísérletek darabszáma (l. NavSubmissionLogGroupedResource).
     *
     * ALAPÉRTELMEZETTEN a hibás/elutasított/beavatkozást igénylő számlák beküldéseire
     * szűrve (?status=errors) — ez a napló egész célja: a hibás/függő halmaz mérete
     * a monitorozható mutató. ?status=pending a verdiktre váró, bármi más (pl.
     * ?status=all) a teljes napló. A szűrés a KAPCSOLÓDÓ SZÁMLA jelenlegi
     * nav_status-án fut, nem az egyes napló-sor saját (történeti) mezőjén — így egy
     * időközben sikerrel lezárt számla korábbi hibás kísérlete nem tartja tévesen a
     * "hibás" listán.
     *
     * A lapozás SZÁMLÁKAT lapoz, nem sorokat: a "legutolsó napló-id számlánként"
     * alkérdés (MAX(id), ami az append-only táblán — UPDATED_AT nincs — biztonságos
     * proxy a created_at szerinti legutolsóra) pontosan egy sort ad számlánként, a
     * külső paginate() ezen fut, tehát a total/last_page már számla-számot tükröz.
     */
    public function index(Request $request, CurrentCompany $currentCompany)
    {
        $this->authorize('nav.log.view');

        $status = $request->string('status')->trim()->value() ?: 'errors';
        $invoiceNumber = $request->string('invoice_number')->trim()->value();
        $dateFrom = $request->string('date_from')->trim()->value();
        $dateTo = $request->string('date_to')->trim()->value();

        $filtered = NavSubmissionLog::query()
            ->forCompany($currentCompany->id())
            ->whereHas('invoice', function ($query) use ($status, $invoiceNumber) {
                if ($status === 'errors') {
                    $query->whereIn('nav_status', self::ERROR_STATUSES);
                } elseif ($status === 'pending') {
                    $query->where('nav_status', NavStatus::Sent->value);
                }

                if ($invoiceNumber !== '') {
                    $query->where('invoice_number', 'ilike', "%{$invoiceNumber}%");
                }
            })
            ->when($dateFrom !== '', fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($q) => $q->whereDate('created_at', '<=', $dateTo));

        $latestPerInvoice = (clone $filtered)
            ->selectRaw('MAX(id) as id')
            ->groupBy('invoice_id');

        $logs = NavSubmissionLog::query()
            ->joinSub($latestPerInvoice, 'latest_per_invoice', fn ($join) => $join->on(
                'nav_submission_logs.id', '=', 'latest_per_invoice.id'
            ))
            ->with(['invoice:id,invoice_number,nav_status,partner_id', 'invoice.partner:id,name'])
            ->orderByDesc('nav_submission_logs.created_at')
            ->paginate($this->perPage($request, 50), ['nav_submission_logs.*']);

        $attemptCounts = (clone $filtered)
            ->whereIn('invoice_id', $logs->pluck('invoice_id'))
            ->selectRaw('invoice_id, COUNT(*) as attempt_count')
            ->groupBy('invoice_id')
            ->pluck('attempt_count', 'invoice_id');

        $logs->getCollection()->each(function (NavSubmissionLog $log) use ($attemptCounts) {
            $log->attempt_count = (int) ($attemptCounts[$log->invoice_id] ?? 1);
        });

        return NavSubmissionLogGroupedResource::collection($logs);
    }

    /**
     * Egyetlen naplóbejegyzés részletei — EZ adja vissza a request_xml/response_xml-t.
     */
    public function show(NavSubmissionLog $nav_submission_log)
    {
        $this->assertBelongsToCurrentCompany($nav_submission_log);
        $this->authorize('nav.log.view');

        return new NavSubmissionLogDetailResource($nav_submission_log);
    }
}
