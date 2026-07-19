<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Egyetlen hely az összes riport közös aggregációs szabályára. Ha ezek
 * szétszóródnának a négy végpont implementációjában, idővel divergálnának,
 * és a riportok csendben eltérő (hibás) számokat adnának.
 *
 * Öt szabály, mindegyik módszeresen alkalmazva minden riporton, ahol releváns:
 *
 * 1. PISZKOZAT KIZÁRÁSA — csak `status IN ('issued','storno')` számít, `draft`
 *    soha. A sztornó ÉS az eredeti számla is bekerül (l. 2. pont), csak a
 *    ténylegesen soha ki nem állított piszkozat esik ki.
 *
 * 2. STORNO-ELŐJEL — az `InvoiceService::cancel()`/`ReceiptService::cancel()`
 *    a storno tételeit és összegeit MÁR NEGÁLVA hozza létre (l. Invoice.php,
 *    invoice_items negált quantity/net/vat/gross). Nincs számlatípus-mező
 *    (nincs "helyesbítő" a sémában) — a `status='issued'` eredeti és a
 *    `status='storno'` sztornó EGYÜTT, előjelesen összegződik, nem kell külön
 *    típus-alapú előjel-fordítás. Ez SZÁNDÉKOSAN eltér a Dashboard
 *    `Invoice::scopeNotCancelled()` mintájától — a Dashboard a "jelenlegi
 *    állapotot" mutatja (a sztornózott számlát és a sztornót is kizárja), a
 *    riport viszont a TÖRTÉNETET: mindkét bizonylat megjelenik, abban az
 *    időszakban, amikor ténylegesen kiállították — a bevétel-visszavonás
 *    tehát egy KÉSŐBBI időszak-vödörben jelenhet meg, mint az eredeti tétel.
 *    KIVÉTEL: a kintlévőség-korosításnál (receivablesAging) a "jelenlegi,
 *    ténylegesen fennálló tartozás" a kérdés, ott a Dashboard mintáját
 *    követve a sztornózott eredeti számla TELJESEN kiesik (nem lehet
 *    kintlévőség egy érvénytelenített bizonylaton).
 *
 * 3. DEVIZA-NORMALIZÁLÁS — a riportok pénzneme MINDIG HUF. A számlán tárolt
 *    `exchange_rate` a kiállításkori MNB HUF-középárfolyam, HA a currency
 *    eltér a cég `base_currency`-jétől; ha megegyezik vele, az
 *    `InvoiceService::create()` 1.0-ra rögzíti (ez NEM feltétlenül a valódi
 *    HUF-árfolyam, ha a cég alapdevizája maga sem HUF — l. docs/progress.md
 *    STOP1 jegyzet, ismert, ebben a fázisban nem javított rés, gyakorlatban
 *    minden cég HUF-alapdevizás). A `gross_total_base_currency` mezőt EZÉRT
 *    NEM használjuk — az a cég ALAPDEVIZÁJÁBAN van, ami nem feltétlenül HUF.
 *    HUF-on számlázott tételnél nincs konverzió (currency='HUF' → az összeg
 *    változatlan). Az exchange_rate DB-szinten NOT NULL, ezért a "hiányzó
 *    árfolyam" eset normál API-forgalomból nem fordulhat elő — a
 *    skip/warning-mechanizmus mégis megvan, védekező jelleggel (l.
 *    skipExpr()), közvetlen DB-beszúrással tesztelve.
 *
 * 4. DÁTUM-ALAP — `date_basis` paraméter (fulfillment|issue, default
 *    fulfillment). Kivétel: a kintlévőség-korosítás mindig `due_date`
 *    szerint számol, nincs date_basis paramétere.
 *
 * 5. AGGREGÁCIÓ SQL-BEN — `date_trunc()` PostgreSQL oldalon, GROUP BY-jal;
 *    nincs PHP-oldali rekord-gyűjtés. Az `issue_date`/`fulfillment_date`/
 *    `due_date` mezők Postgres DATE típusúak (nincs időkomponens), ezért a
 *    `date_trunc()`-ra NEM alkalmazunk `AT TIME ZONE`-t — egy DATE oszlopra
 *    alkalmazott AT TIME ZONE valójában eltolná a naptári napot (éjféli
 *    timestamppá alakítaná, majd időzóna szerint shiftelné), ami ÚJ hibát
 *    vezetne be, nem a leírt "hónaphatár-csúszást" javítaná. Az explicit
 *    Europe/Budapest-kezelés ott releváns, ahol a szerver "ma" dátumát
 *    számítjuk (pl. receivablesAging `as_of` alapértéke) — az app
 *    `config('app.timezone')`-ja UTC, ezért ott kifejezetten
 *    `Carbon::now('Europe/Budapest')`-et használunk `today()` helyett.
 */
class ReportService
{
    private const CACHE_TTL_SECONDS = 600; // 10 perc

    /** query-param → tényleges dátumoszlop (whitelist — SOHA ne kerüljön ide nyers user-input) */
    private const DATE_BASIS_COLUMNS = [
        'fulfillment' => 'fulfillment_date',
        'issue' => 'issue_date',
    ];

    private const CANCELLABLE_STATUSES = ['issued', 'storno'];

    // ─── 1) Számla-riport ────────────────────────────────────────────────

    public function invoicesReport(Company $company, array $filters): array
    {
        return $this->reportsCache()->remember(
            $this->cacheKey('invoices', $company->id, $filters),
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeInvoicesReport($company, $filters),
        );
    }

    private function computeInvoicesReport(Company $company, array $filters): array
    {
        $granularity = $filters['granularity'] ?? 'month';
        $dateBasis = $filters['date_basis'] ?? 'fulfillment';
        $dateColumn = self::DATE_BASIS_COLUMNS[$dateBasis];
        $includeReceipts = ! empty($filters['include_receipts']);

        [$from, $to] = $this->resolveDateRange($filters['from'], $filters['to'], $granularity);
        $periods = $this->periodSequence(Carbon::parse($from), Carbon::parse($to), $granularity);
        $periodFormat = $granularity === 'month' ? 'Y-m' : 'Y-m-d';

        $skip = $this->skipExpr('invoices.currency', 'invoices.exchange_rate');
        $netHuf = $this->hufExpr('invoices.net_total', 'invoices.currency', 'invoices.exchange_rate');
        $vatHuf = $this->hufExpr('invoices.vat_total', 'invoices.currency', 'invoices.exchange_rate');
        $grossHuf = $this->hufExpr('invoices.gross_total', 'invoices.currency', 'invoices.exchange_rate');
        $paidHuf = $this->hufExpr('COALESCE(inv_payments.paid_amount, 0)', 'invoices.currency', 'invoices.exchange_rate');

        $paymentsSub = DB::table('payments')
            ->select('payable_id', 'currency', DB::raw('SUM(amount) as paid_amount'))
            ->where('company_id', $company->id)
            ->where('payable_type', Invoice::class)
            ->groupBy('payable_id', 'currency');

        $rows = DB::table('invoices')
            ->leftJoinSub($paymentsSub, 'inv_payments', function ($join) {
                $join->on('inv_payments.payable_id', '=', 'invoices.id')
                    ->on('inv_payments.currency', '=', 'invoices.currency');
            })
            ->where('invoices.company_id', $company->id)
            ->whereIn('invoices.status', self::CANCELLABLE_STATUSES)
            ->whereDate("invoices.{$dateColumn}", '>=', $from)
            ->whereDate("invoices.{$dateColumn}", '<=', $to)
            ->when(! empty($filters['partner_id']), fn ($q) => $q->where('invoices.partner_id', $filters['partner_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('invoices.payment_status', $filters['status']))
            ->selectRaw(
                "date_trunc('{$granularity}', invoices.{$dateColumn}) as period_bucket,".
                "SUM(CASE WHEN {$skip} = 0 THEN 1 ELSE 0 END) as invoice_count,".
                "SUM(CASE WHEN {$skip} = 0 THEN {$netHuf} ELSE 0 END) as net_total,".
                "SUM(CASE WHEN {$skip} = 0 THEN {$vatHuf} ELSE 0 END) as vat_total,".
                "SUM(CASE WHEN {$skip} = 0 THEN {$grossHuf} ELSE 0 END) as gross_total,".
                "SUM(CASE WHEN {$skip} = 0 THEN {$paidHuf} ELSE 0 END) as paid_total,".
                "SUM(CASE WHEN {$skip} = 0 THEN {$grossHuf} - {$paidHuf} ELSE 0 END) as outstanding_total,".
                "SUM({$skip}) as skipped_count"
            )
            ->groupBy('period_bucket')
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->period_bucket)->format($periodFormat));

        $receiptRows = collect();
        if ($includeReceipts) {
            $receiptDateExpr = $dateBasis === 'fulfillment' ? 'COALESCE(fulfillment_date, issue_date)' : 'issue_date';
            $receiptHuf = $this->hufExpr('gross_total', 'currency', 'exchange_rate');

            $receiptRows = DB::table('receipts')
                ->where('company_id', $company->id)
                ->whereIn('status', self::CANCELLABLE_STATUSES)
                ->whereRaw("{$receiptDateExpr} >= ?", [$from])
                ->whereRaw("{$receiptDateExpr} <= ?", [$to])
                ->when(! empty($filters['partner_id']), fn ($q) => $q->where('partner_id', $filters['partner_id']))
                ->selectRaw(
                    "date_trunc('{$granularity}', {$receiptDateExpr}) as period_bucket,".
                    'COUNT(*) as receipt_count,'.
                    "SUM({$receiptHuf}) as receipt_gross_total"
                )
                ->groupBy('period_bucket')
                ->get()
                ->keyBy(fn ($r) => Carbon::parse($r->period_bucket)->format($periodFormat));
        }

        $totals = ['invoice_count' => 0, 'net_total' => 0.0, 'vat_total' => 0.0, 'gross_total' => 0.0, 'paid_total' => 0.0, 'outstanding_total' => 0.0];
        $receiptTotals = ['receipt_count' => 0, 'receipt_gross_total' => 0.0];
        $skippedCount = 0;

        $periodEntries = [];
        foreach ($periods as $period) {
            $row = $rows->get($period);

            $entry = [
                'period' => $period,
                'invoice_count' => $row ? (int) $row->invoice_count : 0,
                'net_total' => $row ? round((float) $row->net_total, 2) : 0.0,
                'vat_total' => $row ? round((float) $row->vat_total, 2) : 0.0,
                'gross_total' => $row ? round((float) $row->gross_total, 2) : 0.0,
                'paid_total' => $row ? round((float) $row->paid_total, 2) : 0.0,
                'outstanding_total' => $row ? round((float) $row->outstanding_total, 2) : 0.0,
            ];

            if ($row) {
                $totals['invoice_count'] += (int) $row->invoice_count;
                $totals['net_total'] += (float) $row->net_total;
                $totals['vat_total'] += (float) $row->vat_total;
                $totals['gross_total'] += (float) $row->gross_total;
                $totals['paid_total'] += (float) $row->paid_total;
                $totals['outstanding_total'] += (float) $row->outstanding_total;
                $skippedCount += (int) $row->skipped_count;
            }

            if ($includeReceipts) {
                $rr = $receiptRows->get($period);
                // Külön mezőkben — a nyugta és a számla külön jogi objektum, NEM olvasztjuk
                // bele a számla-összegekbe (duplázás-veszély).
                $entry['receipt_count'] = $rr ? (int) $rr->receipt_count : 0;
                $entry['receipt_gross_total'] = $rr ? round((float) $rr->receipt_gross_total, 2) : 0.0;

                if ($rr) {
                    $receiptTotals['receipt_count'] += (int) $rr->receipt_count;
                    $receiptTotals['receipt_gross_total'] += (float) $rr->receipt_gross_total;
                }
            }

            $periodEntries[] = $entry;
        }

        foreach (['net_total', 'vat_total', 'gross_total', 'paid_total', 'outstanding_total'] as $key) {
            $totals[$key] = round($totals[$key], 2);
        }
        $receiptTotals['receipt_gross_total'] = round($receiptTotals['receipt_gross_total'], 2);

        return [
            'periods' => $periodEntries,
            'totals' => $includeReceipts ? array_merge($totals, $receiptTotals) : $totals,
            'warnings' => ['skipped_count' => $skippedCount],
        ];
    }

    // ─── 2) Termék-riport ────────────────────────────────────────────────

    public function productsReport(Company $company, array $filters): array
    {
        return $this->reportsCache()->remember(
            $this->cacheKey('products', $company->id, $filters),
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeProductsReport($company, $filters),
        );
    }

    private function computeProductsReport(Company $company, array $filters): array
    {
        $dateBasis = $filters['date_basis'] ?? 'fulfillment';
        $dateColumn = self::DATE_BASIS_COLUMNS[$dateBasis];
        $limit = $filters['limit'] ?? 50;
        $offset = $filters['offset'] ?? 0;
        $orderBy = $filters['order_by'] ?? 'revenue';

        [$from, $to] = $this->resolveDateRange($filters['from'], $filters['to'], 'month');

        $skip = $this->skipExpr('i.currency', 'i.exchange_rate');
        $netHuf = $this->hufExpr('ii.net_amount', 'i.currency', 'i.exchange_rate');

        // A megjelenítendő név/mértékegység MINDIG a tételből jön (historikus,
        // számlázáskori érték), soha a products táblából — törölt termék tétele
        // is így jelenik meg. product_id NULL esetén a leírás a csoportosító kulcs.
        $groupKeyExpr = "COALESCE(ii.product_id::text, 'anon:' || ii.description)";

        $base = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', self::CANCELLABLE_STATUSES)
            ->whereDate("i.{$dateColumn}", '>=', $from)
            ->whereDate("i.{$dateColumn}", '<=', $to);

        $orderColumn = $orderBy === 'quantity' ? 'total_quantity' : 'net_revenue';

        $rows = (clone $base)
            ->selectRaw(
                "{$groupKeyExpr} as group_key, ii.product_id,".
                '(array_agg(ii.description ORDER BY i.issue_date DESC))[1] as product_name,'.
                '(array_agg(ii.unit ORDER BY i.issue_date DESC))[1] as unit,'.
                'SUM(ii.quantity) as total_quantity,'.
                "SUM(CASE WHEN {$skip} = 0 THEN {$netHuf} ELSE 0 END) as net_revenue,".
                'COUNT(DISTINCT ii.invoice_id) as invoice_count,'.
                "SUM({$skip}) as skipped_count"
            )
            ->groupByRaw("{$groupKeyExpr}, ii.product_id")
            ->orderByDesc($orderColumn)
            ->limit($limit)
            ->offset($offset)
            ->get();

        $totalsRow = (clone $base)
            ->selectRaw(
                "SUM(CASE WHEN {$skip} = 0 THEN {$netHuf} ELSE 0 END) as total_revenue,".
                "SUM({$skip}) as skipped_count"
            )
            ->first();

        $items = $rows->map(function ($r) {
            $quantity = (float) $r->total_quantity;
            $revenue = (float) $r->net_revenue;

            return [
                'product_id' => $r->product_id !== null ? (int) $r->product_id : null,
                'name' => $r->product_name,
                'unit' => $r->unit,
                'quantity' => round($quantity, 3),
                'net_revenue' => round($revenue, 2),
                'invoice_count' => (int) $r->invoice_count,
                // Súlyozott átlag (nettó árbevétel / mennyiség) — 0, ha a nettó
                // mennyiség pontosan nullára jön ki (pl. teljesen sztornózott tétel).
                'average_unit_price' => $quantity != 0.0 ? round($revenue / $quantity, 2) : 0.0,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'totals' => ['net_revenue' => round((float) ($totalsRow->total_revenue ?? 0), 2)],
            'warnings' => ['skipped_count' => (int) ($totalsRow->skipped_count ?? 0)],
        ];
    }

    // ─── 3) Kintlévőség-korosítás ────────────────────────────────────────

    public function receivablesAging(Company $company, array $filters): array
    {
        return $this->reportsCache()->remember(
            $this->cacheKey('receivables-aging', $company->id, $filters),
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeReceivablesAging($company, $filters),
        );
    }

    private function computeReceivablesAging(Company $company, array $filters): array
    {
        $asOf = isset($filters['as_of']) ? Carbon::parse($filters['as_of'])->toDateString() : $this->today();
        $partnerId = $filters['partner_id'] ?? null;

        $skip = $this->skipExpr('i.currency', 'i.exchange_rate');
        $grossHuf = $this->hufExpr('i.gross_total', 'i.currency', 'i.exchange_rate');
        $paidHuf = $this->hufExpr('COALESCE(p.paid_amount, 0)', 'i.currency', 'i.exchange_rate');
        $remaining = "CASE WHEN {$skip} = 0 THEN {$grossHuf} - {$paidHuf} ELSE 0 END";
        // Postgres date - date = egész napok száma; pozitív = lejárt, negatív = még esedékes.
        $daysOverdue = "('{$asOf}'::date - i.due_date)";

        $paymentsSub = DB::table('payments')
            ->select('payable_id', 'currency', DB::raw('SUM(amount) as paid_amount'))
            ->where('company_id', $company->id)
            ->where('payable_type', Invoice::class)
            ->groupBy('payable_id', 'currency');

        $base = DB::table('invoices as i')
            ->leftJoinSub($paymentsSub, 'p', function ($join) {
                $join->on('p.payable_id', '=', 'i.id')->on('p.currency', '=', 'i.currency');
            })
            ->where('i.company_id', $company->id)
            ->where('i.status', 'issued')
            ->whereIn('i.payment_status', ['open', 'partial'])
            // A sztornózott eredeti számla NEM lehet kintlévőség — ez a "jelenlegi
            // fennálló tartozás" kérdése, ezért itt (a többi riporttól eltérően,
            // l. osztály-docblock 2. pont) TELJESEN kizárjuk, a Dashboard mintáját
            // követve, nem a status='storno' pár előjeles összegzésével.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('invoices as s')
                    ->whereColumn('s.storno_of_invoice_id', 'i.id');
            })
            ->when($partnerId, fn ($q) => $q->where('i.partner_id', $partnerId));

        $bandSelect =
            "SUM(CASE WHEN ({$daysOverdue}) < 0 THEN {$remaining} ELSE 0 END) as not_due,".
            "SUM(CASE WHEN ({$daysOverdue}) BETWEEN 0 AND 30 THEN {$remaining} ELSE 0 END) as band_0_30,".
            "SUM(CASE WHEN ({$daysOverdue}) BETWEEN 31 AND 60 THEN {$remaining} ELSE 0 END) as band_31_60,".
            "SUM(CASE WHEN ({$daysOverdue}) BETWEEN 61 AND 90 THEN {$remaining} ELSE 0 END) as band_61_90,".
            "SUM(CASE WHEN ({$daysOverdue}) > 90 THEN {$remaining} ELSE 0 END) as band_90_plus,".
            "SUM({$remaining}) as total,".
            "SUM({$skip}) as skipped_count";

        $rows = (clone $base)
            ->selectRaw("i.partner_id, {$bandSelect}")
            ->groupBy('i.partner_id')
            ->orderByDesc('total')
            ->get();

        $totalsRow = (clone $base)->selectRaw($bandSelect)->first();

        $partnerIds = $rows->pluck('partner_id')->all();
        $names = Partner::withoutGlobalScope('company')->whereIn('id', $partnerIds)->pluck('name', 'id');

        $partners = $rows->map(fn ($r) => [
            'partner_id' => (int) $r->partner_id,
            'partner_name' => $names->get($r->partner_id),
            'not_due' => round((float) $r->not_due, 2),
            'band_0_30' => round((float) $r->band_0_30, 2),
            'band_31_60' => round((float) $r->band_31_60, 2),
            'band_61_90' => round((float) $r->band_61_90, 2),
            'band_90_plus' => round((float) $r->band_90_plus, 2),
            'total' => round((float) $r->total, 2),
        ])->values()->all();

        return [
            'as_of' => $asOf,
            'partners' => $partners,
            'totals' => [
                'not_due' => round((float) ($totalsRow->not_due ?? 0), 2),
                'band_0_30' => round((float) ($totalsRow->band_0_30 ?? 0), 2),
                'band_31_60' => round((float) ($totalsRow->band_31_60 ?? 0), 2),
                'band_61_90' => round((float) ($totalsRow->band_61_90 ?? 0), 2),
                'band_90_plus' => round((float) ($totalsRow->band_90_plus ?? 0), 2),
                'total' => round((float) ($totalsRow->total ?? 0), 2),
            ],
            'warnings' => ['skipped_count' => (int) ($totalsRow->skipped_count ?? 0)],
        ];
    }

    // ─── 4) ÁFA-összesítő ────────────────────────────────────────────────

    public function vatSummary(Company $company, array $filters): array
    {
        return $this->reportsCache()->remember(
            $this->cacheKey('vat-summary', $company->id, $filters),
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeVatSummary($company, $filters),
        );
    }

    private function computeVatSummary(Company $company, array $filters): array
    {
        $dateBasis = $filters['date_basis'] ?? 'fulfillment';
        $dateColumn = self::DATE_BASIS_COLUMNS[$dateBasis];

        [$from, $to] = $this->resolveDateRange($filters['from'], $filters['to'], 'month');

        $skip = $this->skipExpr('i.currency', 'i.exchange_rate');
        $netHuf = $this->hufExpr('ii.net_amount', 'i.currency', 'i.exchange_rate');
        $vatHuf = $this->hufExpr('ii.vat_amount', 'i.currency', 'i.exchange_rate');

        $rows = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('vat_rates as vr', 'vr.id', '=', 'ii.vat_rate_id')
            ->where('i.company_id', $company->id)
            ->whereIn('i.status', self::CANCELLABLE_STATUSES)
            ->whereDate("i.{$dateColumn}", '>=', $from)
            ->whereDate("i.{$dateColumn}", '<=', $to)
            ->selectRaw(
                "date_trunc('month', i.{$dateColumn}) as period_bucket,".
                'vr.id as vat_rate_id, vr.name as vat_rate_name, vr.nav_code as vat_rate_nav_code,'.
                "SUM(CASE WHEN {$skip} = 0 THEN {$netHuf} ELSE 0 END) as net_total,".
                "SUM(CASE WHEN {$skip} = 0 THEN {$vatHuf} ELSE 0 END) as vat_total,".
                "SUM({$skip}) as skipped_count"
            )
            ->groupBy('period_bucket', 'vr.id', 'vr.name', 'vr.nav_code')
            ->orderBy('period_bucket')
            ->get();

        $items = $rows->map(fn ($r) => [
            'period' => Carbon::parse($r->period_bucket)->format('Y-m'),
            'vat_rate_id' => (int) $r->vat_rate_id,
            'vat_category' => $r->vat_rate_name,
            'nav_code' => $r->vat_rate_nav_code,
            'net_total' => round((float) $r->net_total, 2),
            'vat_total' => round((float) $r->vat_total, 2),
            'gross_total' => round((float) $r->net_total + (float) $r->vat_total, 2),
        ])->values()->all();

        $byCategory = [];
        $grand = ['net_total' => 0.0, 'vat_total' => 0.0, 'gross_total' => 0.0];
        $skippedCount = 0;

        foreach ($rows as $r) {
            $key = (int) $r->vat_rate_id;
            if (! isset($byCategory[$key])) {
                $byCategory[$key] = [
                    'vat_rate_id' => $key,
                    'vat_category' => $r->vat_rate_name,
                    'nav_code' => $r->vat_rate_nav_code,
                    'net_total' => 0.0,
                    'vat_total' => 0.0,
                    'gross_total' => 0.0,
                ];
            }
            $byCategory[$key]['net_total'] += (float) $r->net_total;
            $byCategory[$key]['vat_total'] += (float) $r->vat_total;
            $byCategory[$key]['gross_total'] += (float) $r->net_total + (float) $r->vat_total;

            $grand['net_total'] += (float) $r->net_total;
            $grand['vat_total'] += (float) $r->vat_total;
            $grand['gross_total'] += (float) $r->net_total + (float) $r->vat_total;

            $skippedCount += (int) $r->skipped_count;
        }

        foreach ($byCategory as &$category) {
            $category['net_total'] = round($category['net_total'], 2);
            $category['vat_total'] = round($category['vat_total'], 2);
            $category['gross_total'] = round($category['gross_total'], 2);
        }
        unset($category);

        return [
            'items' => $items,
            'totals' => [
                'by_category' => array_values($byCategory),
                'grand_total' => array_map(fn ($v) => round($v, 2), $grand),
            ],
            'warnings' => ['skipped_count' => $skippedCount],
        ];
    }

    // ─── Segédmetódusok ──────────────────────────────────────────────────

    /**
     * A riport-cache 'reports' taggel van megjelölve (Laravel cache-tagging,
     * a redis store támogatja) — így egy célzott `Cache::store('redis')
     * ->tags(['reports'])->flush()` (pl. tesztek setUp()-jában) KIZÁRÓLAG a
     * riport-bejegyzéseket üríti, nem a teljes Redis 'cache' logikai
     * adatbázist. Sima `Cache::store('redis')->flush()` a redis store teljes
     * `FLUSHDB`-jét hívná — ez ugyanazt a Redis-adatbázist ürítené ki, amit
     * (jövőbeli bővítésnél) más funkció is használhatna, ezért kerülendő.
     */
    private function reportsCache()
    {
        return Cache::store('redis')->tags(['reports']);
    }

    /** HUF-konverziós CASE-kifejezés — l. osztály-docblock 3. pont. */
    private function hufExpr(string $amountExpr, string $currencyCol, string $rateCol): string
    {
        return "CASE WHEN {$currencyCol} = 'HUF' THEN {$amountExpr} ELSE {$amountExpr} * {$rateCol} END";
    }

    /**
     * 1, ha a sor nem-HUF devizájú ÉS az árfolyam hiányzik/érvénytelen — ilyen
     * sor kimarad az összegekből, de a warnings.skipped_count-ba beleszámít.
     * A jelenlegi validáció mellett (exchange_rate NOT NULL) ez normál
     * API-forgalomból nem fordul elő — védekező jelleggel implementálva.
     */
    private function skipExpr(string $currencyCol, string $rateCol): string
    {
        return "CASE WHEN {$currencyCol} <> 'HUF' AND ({$rateCol} IS NULL OR {$rateCol} <= 0) THEN 1 ELSE 0 END";
    }

    /**
     * 'YYYY-MM' vagy 'YYYY-MM-DD' bemenetet fogad; a 'to' hónap-alakot a
     * hónap UTOLSÓ napjára zárja (inkluzív tartomány).
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(string $from, string $to, string $granularity): array
    {
        $fromDate = strlen($from) === 7 ? Carbon::parse($from.'-01') : Carbon::parse($from);
        $toDate = strlen($to) === 7 ? Carbon::parse($to.'-01')->endOfMonth() : Carbon::parse($to);

        return [$fromDate->toDateString(), $toDate->toDateString()];
    }

    /**
     * A [from, to] tartomány teljes időszak-sorozata (üres időszakok is
     * megjelennek nulla értékkel a hívó oldalán — a frontend diagramnak
     * folytonos sorozat kell).
     *
     * @return string[]
     */
    private function periodSequence(Carbon $from, Carbon $to, string $granularity): array
    {
        $periods = [];

        if ($granularity === 'day') {
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $periods[] = $cursor->toDateString();
                $cursor->addDay();
            }

            return $periods;
        }

        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();
        while ($cursor->lte($end)) {
            $periods[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        return $periods;
    }

    /** "Ma", Europe/Budapest szerint — l. osztály-docblock 5. pont (app timezone UTC). */
    private function today(): string
    {
        return Carbon::now('Europe/Budapest')->toDateString();
    }

    private function cacheKey(string $report, int $companyId, array $filters): string
    {
        ksort($filters);

        return sprintf('reports:%d:%s:%s', $companyId, $report, md5(json_encode($filters)));
    }
}
