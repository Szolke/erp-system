<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\NavStatus;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Számolja a bejelentkezés utáni nyitólap widgetjeinek adatait. Jogosultsággal
 * és modul-gatinggel NEM foglalkozik — az a DashboardController dolga, ami
 * eldönti, egy-egy widget metódusa egyáltalán meghívódjon-e.
 *
 * Minden lekérdezés explicit company_id-vel szűr (nem a BelongsToCompany
 * globális scope-jára hagyatkozik) — a payments JOIN/subquery utak elhagyják
 * az Eloquent modell-kontextust, ott a globális scope nem érvényesülne.
 */
class DashboardService
{
    /**
     * Kifizetetlen számlák devizánkénti összege: open → teljes gross_total,
     * partial → gross_total mínusz az azonos devizájú kifizetések összege.
     */
    public function unpaidTotals(Company $company): array
    {
        return $this->mergeTotals(
            $this->openTotalsByCurrency($company, overdueOnly: false),
            $this->partialTotalsByCurrency($company, overdueOnly: false),
        );
    }

    /**
     * A kifizetetlen szűrő + due_date < ma. `oldest_days_overdue` a
     * legrégebben lejárt kifizetetlen számla lejárat óta eltelt napjainak
     * száma (null, ha nincs lejárt számla).
     */
    public function overdueTotals(Company $company): array
    {
        $totals = $this->mergeTotals(
            $this->openTotalsByCurrency($company, overdueOnly: true),
            $this->partialTotalsByCurrency($company, overdueOnly: true),
        );

        $oldestDueDate = Invoice::query()
            ->where('company_id', $company->id)
            ->where('status', InvoiceStatus::Issued->value)
            ->notCancelled()
            ->whereIn('payment_status', [PaymentStatus::Open->value, PaymentStatus::Partial->value])
            ->whereDate('due_date', '<', today())
            ->min('due_date');

        return [
            'totals' => $totals,
            'oldest_days_overdue' => $oldestDueDate !== null
                ? today()->diffInDays(Carbon::parse($oldestDueDate), true)
                : null,
        ];
    }

    /**
     * Alapszűrő + issue_date az aktuális naptári hónapban. Fizetéstől
     * függetlenül a teljes gross_total számít.
     */
    public function monthlyRevenue(Company $company): array
    {
        $from = today()->startOfMonth();
        $to = today()->endOfMonth();

        $totals = Invoice::query()
            ->where('company_id', $company->id)
            ->where('status', InvoiceStatus::Issued->value)
            ->notCancelled()
            ->whereDate('issue_date', '>=', $from)
            ->whereDate('issue_date', '<=', $to)
            ->selectRaw('currency, SUM(gross_total) AS amount, COUNT(*) AS count')
            ->groupBy('currency')
            ->get();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->formatTotals($totals),
        ];
    }

    /**
     * A kifizetetlen szűrő, due_date szerint növekvő sorrendben, limitálva.
     * Az open és partial ágat UNION ALL-lal fésüli össze egyetlen, due_date
     * szerint rendezett + limitált lekérdezésbe (nem tölti be az összes
     * kifizetetlen számlát, hogy csak utána vágja le PHP-ben az élen levő 5-öt).
     */
    public function oldestUnpaid(Company $company, int $limit = 5): array
    {
        $open = Invoice::query()
            ->where('company_id', $company->id)
            ->where('status', InvoiceStatus::Issued->value)
            ->notCancelled()
            ->where('payment_status', PaymentStatus::Open->value)
            ->selectRaw('id, invoice_number, partner_id, currency, gross_total AS remaining, due_date')
            ->getQuery();

        $partial = Invoice::query()
            ->where('invoices.company_id', $company->id)
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->notCancelled()
            ->where('invoices.payment_status', PaymentStatus::Partial->value)
            ->leftJoin('payments', function ($join) use ($company) {
                $join->on('payments.payable_id', '=', 'invoices.id')
                    ->where('payments.payable_type', Invoice::class)
                    ->whereColumn('payments.currency', 'invoices.currency')
                    ->where('payments.company_id', $company->id);
            })
            ->selectRaw(
                'invoices.id, invoices.invoice_number, invoices.partner_id, invoices.currency, '.
                '(invoices.gross_total - COALESCE(SUM(payments.amount), 0)) AS remaining, invoices.due_date'
            )
            ->groupBy('invoices.id', 'invoices.invoice_number', 'invoices.partner_id', 'invoices.currency', 'invoices.gross_total', 'invoices.due_date')
            ->getQuery();

        $rows = $open->unionAll($partial)
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        $partnerNames = Partner::withoutGlobalScope('company')
            ->whereIn('id', $rows->pluck('partner_id'))
            ->pluck('name', 'id');

        return $rows->map(function ($row) use ($partnerNames) {
            $dueDate = Carbon::parse($row->due_date);

            return [
                'id' => (int) $row->id,
                'number' => $row->invoice_number,
                'partner_name' => $partnerNames->get($row->partner_id),
                'gross_total' => round((float) $row->remaining, 2),
                'currency' => $row->currency,
                'due_date' => $dueDate->toDateString(),
                // SZÁNDÉKOSAN előjeles (a mezőnév ezt nem árulja el): pozitív = ennyi
                // napja lejárt, negatív = ennyi nap múlva esedékes. Ez a lista NEM
                // szűr lejártságra, a frontend az előjel alapján különbözteti meg a
                // lejárt (piros) és a még nem esedékes (semleges) sorokat — NE alakítsd
                // abszolút értékre.
                'days_overdue' => $this->daysOverdue($dueDate),
            ];
        })->values()->all();
    }

    /**
     * `error` állapotú NAV-küldések száma + a legutolsó nav_sent_at időpont.
     */
    public function navStatus(Company $company): array
    {
        $errorCount = Invoice::query()
            ->where('company_id', $company->id)
            ->where('nav_status', NavStatus::Error->value)
            ->count();

        $lastSentAt = Invoice::query()
            ->where('company_id', $company->id)
            ->whereNotNull('nav_sent_at')
            ->max('nav_sent_at');

        return [
            'error_count' => $errorCount,
            'last_sent_at' => $lastSentAt !== null ? Carbon::parse($lastSentAt)->toIso8601String() : null,
        ];
    }

    // ─── Segédmetódusok ────────────────────────────────────────────────────

    private function openTotalsByCurrency(Company $company, bool $overdueOnly): Collection
    {
        return Invoice::query()
            ->where('company_id', $company->id)
            ->where('status', InvoiceStatus::Issued->value)
            ->notCancelled()
            ->where('payment_status', PaymentStatus::Open->value)
            ->when($overdueOnly, fn ($q) => $q->whereDate('due_date', '<', today()))
            ->selectRaw('currency, SUM(gross_total) AS amount, COUNT(*) AS count')
            ->groupBy('currency')
            ->get();
    }

    /**
     * A payments JOIN-t szándékosan csak itt, a partial ágban futtatjuk —
     * open státusznál definíció szerint nincs mit kivonni, a teljes gross_total
     * a fennmaradó összeg (l. DashboardService osztály-doc).
     */
    private function partialTotalsByCurrency(Company $company, bool $overdueOnly): Collection
    {
        $inner = Invoice::query()
            ->where('invoices.company_id', $company->id)
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->notCancelled()
            ->where('invoices.payment_status', PaymentStatus::Partial->value)
            ->when($overdueOnly, fn ($q) => $q->whereDate('invoices.due_date', '<', today()))
            ->leftJoin('payments', function ($join) use ($company) {
                $join->on('payments.payable_id', '=', 'invoices.id')
                    ->where('payments.payable_type', Invoice::class)
                    ->whereColumn('payments.currency', 'invoices.currency')
                    ->where('payments.company_id', $company->id);
            })
            ->selectRaw('invoices.id, invoices.currency, (invoices.gross_total - COALESCE(SUM(payments.amount), 0)) AS remaining')
            ->groupBy('invoices.id', 'invoices.currency', 'invoices.gross_total');

        return DB::query()
            ->fromSub($inner, 'partial_remaining')
            ->selectRaw('currency, SUM(remaining) AS amount, COUNT(*) AS count')
            ->groupBy('currency')
            ->get();
    }

    private function mergeTotals(Collection $open, Collection $partial): array
    {
        $merged = [];

        foreach ($open->concat($partial) as $row) {
            $currency = $row->currency;
            if (! isset($merged[$currency])) {
                $merged[$currency] = ['currency' => $currency, 'amount' => 0.0, 'count' => 0];
            }
            $merged[$currency]['amount'] += (float) $row->amount;
            $merged[$currency]['count'] += (int) $row->count;
        }

        foreach ($merged as &$row) {
            $row['amount'] = round($row['amount'], 2);
        }

        return array_values($merged);
    }

    private function formatTotals(Collection $rows): array
    {
        return $rows->map(fn ($row) => [
            'currency' => $row->currency,
            'amount' => round((float) $row->amount, 2),
            'count' => (int) $row->count,
        ])->values()->all();
    }

    /**
     * Pozitív, ha a lejárat a múltban van (lejárt), negatív, ha még csak
     * ezután jár le — abszolút napkülönbségből (explicit `absolute: true`,
     * mert ennek a Carbon-verziónak a diffInDays() alapértelmezetten
     * ELŐJELES eredményt ad) + a saját lessThan()-alapú előjelből számolva.
     */
    private function daysOverdue(Carbon $dueDate): int
    {
        $today = today();
        $due = $dueDate->copy()->startOfDay();
        $distance = $today->diffInDays($due, true);

        return $due->lessThan($today) ? $distance : -$distance;
    }
}
