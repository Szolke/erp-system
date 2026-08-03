<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Support\HufConversion;
use App\Support\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Bizonylatok
 *
 * A számla és nyugta (+ sztornóik) UNION ALL-lal egyesített listája. A
 * `display_status` egy SQL-ben SZÁRMAZTATOTT (nem tárolt) mező — l.
 * invoiceDisplayStatusCaseSql()/receiptDisplayStatusCaseSql() a pontos
 * prioritási sorrendért. A `summary` blokk a TELJES szűrt halmazra számol
 * (nem csak az aktuális oldalra), HUF-ra normalizálva — ugyanazt a
 * HufConversion helpert használja, mint a ReportService (Kimutatások).
 *
 * A lista (index) és az export UGYANAZT a buildUnionSql()-t használja — nincs
 * második, duplikált szűrő-építő logika. A jogosultság mindkét végponton
 * invoice.view/receipt.view (ugyanaz, mint a listáé) — a Kimutatások-modul
 * mintája (külön report.export jog) itt szándékosan NEM került bevezetésre,
 * mert a bizonylatlista-export nem indokol finomabb, a megtekintéstől
 * elválasztott jogosultsági szintet, amíg ezt külön igény nem kéri.
 *
 * Rendezés: a `sort_by`/`sort_dir` query-paraméter WHITELISTELT (l. a lenti
 * SORTABLE_COLUMNS + App\Support\ListSort), ismeretlen érték esetén az eddigi
 * alapértelmezésre (kelt szerint csökkenő) esik vissza. A rendezés az EGYESÍTETT
 * (union) származtatott táblán fut, tehát a company-scope (BelongsToCompany
 * global scope az union AL-lekérdezéseiben) érintetlen marad.
 */
class DocumentController extends Controller
{
    /** display_status értékek, amik kizárólag számlán fordulhatnak elő — ha ez a szűrt
     *  státusz, a nyugta-ágat egyáltalán nem érdemes lekérdezni. */
    private const INVOICE_ONLY_STATUSES = ['draft', 'paid', 'overdue', 'partially_paid'];

    private const DOCUMENT_TYPE_LABELS = [
        'invoice' => 'Számla',
        'invoice_storno' => 'Sztornó számla',
        'receipt' => 'Nyugta',
        'receipt_storno' => 'Sztornó nyugta',
    ];

    /**
     * Rendezhető oszlopok: a FRONTEND oszlopkulcsa (l. frontend/src/columns/documents.js)
     * => az egyesített (union) származtatott tábla oszlopa. Kizárólag ezek az
     * ÉRTÉKEK kerülhetnek ORDER BY-ba — a kérés csak a kulcsot választhatja ki
     * (l. ListSort osztály-docblock).
     *
     * A `status` a SZÁRMAZTATOTT `display_status`-ra rendez, a `type` pedig a
     * `document_type`-ra: mindkettő ábécésorrendben, nem "jelentés" szerint —
     * ez a listanézet célja szempontjából (azonos típusú/állapotú sorok
     * egymás mellé kerüljenek) elegendő.
     */
    private const SORTABLE_COLUMNS = [
        'number'             => 'document_number',
        'type'               => 'document_type',
        'partner'            => 'partner_name',
        'partner_tax_number' => 'partner_tax_number',
        'issue_date'         => 'issue_date',
        'fulfillment_date'   => 'fulfillment_date',
        'due_date'           => 'due_date',
        'net_total'          => 'net_total',
        'vat_total'          => 'vat_total',
        'gross'              => 'gross_total',
        'gross_huf'          => 'gross_total_huf',
        'currency'           => 'currency',
        'payment_status'     => 'payment_status',
        'status'             => 'display_status',
    ];

    /** Az eddigi (rendezés-paraméter nélküli) viselkedés: kelt szerint csökkenő. */
    private const DEFAULT_SORT_KEY = 'issue_date';

    /**
     * Stabilizáló másodlagos szempontok — az eddigi alapértelmezés farka. Az
     * `id` az union két ága között nem egyedi (számla és nyugta id-je ütközhet),
     * ezért ez nem TELJES rendezési determinizmus, csak az eddigivel azonos
     * mértékű stabilizálás a lapozáshoz.
     */
    private const SORT_TIE_BREAKERS = ['issue_date DESC', 'id DESC'];

    private const DISPLAY_STATUS_LABELS = [
        'paid' => 'Fizetve',
        'overdue' => 'Lejárt',
        'partially_paid' => 'Részben fizetve',
        'issued' => 'Kiállított',
        'draft' => 'Piszkozat',
        'storno' => 'Sztornózott',
    ];

    public function index(Request $request)
    {
        [$canInvoice, $canReceipt] = $this->authorizeAccess($request);
        [$unionSql, $bindings] = $this->buildUnionSql($request, $canInvoice, $canReceipt);

        $perPage = $this->perPage($request);
        $page    = max(1, (int) $request->get('page', 1));

        if ($unionSql === null) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'summary' => ['count' => 0, 'gross_total_huf' => 0.0, 'skipped_count' => 0],
            ]);
        }

        $total = (int) DB::selectOne(
            "SELECT COUNT(*) AS total FROM ({$unionSql}) AS d",
            $bindings
        )->total;

        $summary = DB::selectOne(
            "SELECT
                COUNT(*) AS doc_count,
                COALESCE(SUM(gross_total_huf), 0) AS gross_total_huf,
                COALESCE(SUM(CASE WHEN gross_total_huf IS NULL THEN 1 ELSE 0 END), 0) AS skipped_count
             FROM ({$unionSql}) AS d",
            $bindings
        );

        $offset  = ($page - 1) * $perPage;
        $orderBy = $this->orderBySql($request);
        $rows    = DB::select(
            "SELECT * FROM ({$unionSql}) AS d ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / max(1, $perPage)),
            ],
            'summary' => [
                'count'           => (int) $summary->doc_count,
                'gross_total_huf' => round((float) $summary->gross_total_huf, 2),
                'skipped_count'   => (int) $summary->skipped_count,
            ],
        ]);
    }

    /**
     * GET /api/documents/export?format=csv — ugyanazok a szűrő-paraméterek,
     * mint a lista-végponton, de a TELJES szűrt halmazt adja (nincs lapozás).
     * StreamedResponse, nem memóriába épített string — l. ReportController::export()
     * ugyanez a minta a Kimutatásoknál.
     */
    public function export(Request $request): StreamedResponse
    {
        [$canInvoice, $canReceipt] = $this->authorizeAccess($request);
        [$unionSql, $bindings] = $this->buildUnionSql($request, $canInvoice, $canReceipt);

        // Az export ugyanazt a rendezést kapja, mint a lista: a felhasználó a
        // képernyőn látott sorrendben várja a CSV-t is. Paraméter nélkül ez az
        // eddigi (kelt szerint csökkenő) sorrend.
        $orderBy = $this->orderBySql($request);

        $rows = $unionSql === null
            ? []
            : DB::select("SELECT * FROM ({$unionSql}) AS d ORDER BY {$orderBy}", $bindings);

        $dateFrom = $request->string('date_from')->trim()->value();
        $dateTo   = $request->string('date_to')->trim()->value();
        $filename = ($dateFrom !== '' && $dateTo !== '')
            ? "bizonylatok-{$dateFrom}-{$dateTo}.csv"
            : 'bizonylatok-export.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM — enélkül az Excel elrontja az ékezeteket
            fputcsv($handle, [
                'Bizonylatszám', 'Típus', 'Partner neve', 'Partner adószáma',
                'Kelt', 'Teljesítés dátuma', 'Fizetési határidő',
                'Nettó', 'ÁFA', 'Bruttó', 'Pénznem', 'Bruttó (HUF)', 'Állapot',
            ], ';');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->document_number,
                    self::DOCUMENT_TYPE_LABELS[$row->document_type] ?? $row->document_type,
                    $row->partner_name,
                    $row->partner_tax_number,
                    $row->issue_date,
                    $row->fulfillment_date,
                    $row->due_date,
                    $row->net_total,
                    $row->vat_total,
                    $row->gross_total,
                    $row->currency,
                    $row->gross_total_huf,
                    self::DISPLAY_STATUS_LABELS[$row->display_status] ?? $row->display_status,
                ], ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A kérésből feloldott, whitelistelt ORDER BY-töredék az egyesített
     * származtatott táblához. Egyetlen belépési pont az index() és az export()
     * számára, hogy a két végpont rendezése ne csúszhasson szét.
     */
    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY)
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
    }

    /** Ha se invoice.view, se receipt.view: 403 (Gate-en keresztül, l. authorize()). */
    private function authorizeAccess(Request $request): array
    {
        $user       = $request->user();
        $canInvoice = $user->can('invoice.view');
        $canReceipt = $user->can('receipt.view');

        if (! $canInvoice && ! $canReceipt) {
            $this->authorize('invoice.view');
        }

        return [$canInvoice, $canReceipt];
    }

    /**
     * A számla+nyugta UNION ALL felépítése a kérés szűrőiből — index() ÉS
     * export() is ezt hívja, nincs második, duplikált szűrő-építő ág.
     *
     * @return array{0: ?string, 1: array} [unionSql, bindings] — unionSql
     *         null, ha a felhasználónak egyik ágra sincs jogosultsága vagy a
     *         típus/státusz-szűrő miatt egyik ág sem releváns.
     */
    private function buildUnionSql(Request $request, bool $canInvoice, bool $canReceipt): array
    {
        $typeFilter   = $request->string('type')->trim()->value();
        $statusFilter = $request->string('status')->trim()->value();
        $search       = $request->string('search')->trim()->value();
        $dateFrom     = $request->string('date_from')->trim()->value();
        $dateTo       = $request->string('date_to')->trim()->value();
        $currency     = $request->string('currency')->trim()->value();

        $statusExcludesReceipts = $statusFilter !== '' && in_array($statusFilter, self::INVOICE_ONLY_STATUSES, true);

        // 'storno' egy ÖSSZEVONT típus-fül: számla- ÉS nyugta-sztornók együtt.
        $includeInvoices = $canInvoice && in_array($typeFilter, ['', 'invoice', 'invoice_storno', 'storno'], true);
        $includeReceipts = $canReceipt
            && in_array($typeFilter, ['', 'receipt', 'receipt_storno', 'storno'], true)
            && ! $statusExcludesReceipts;

        $parts    = [];
        $bindings = [];

        if ($includeInvoices) {
            $hufExpr  = HufConversion::amountExpr('invoices.gross_total', 'invoices.currency', 'invoices.exchange_rate');
            $skipExpr = HufConversion::skipExpr('invoices.currency', 'invoices.exchange_rate');

            $q = Invoice::query()
                ->leftJoin('partners', 'partners.id', '=', 'invoices.partner_id')
                ->selectRaw(
                    "invoices.id,
                     'invoice' AS model_type,
                     CASE WHEN invoices.storno_of_invoice_id IS NOT NULL
                          THEN 'invoice_storno' ELSE 'invoice' END AS document_type,
                     invoices.invoice_number AS document_number,
                     partners.name AS partner_name,
                     partners.tax_number AS partner_tax_number,
                     invoices.issue_date,
                     invoices.fulfillment_date,
                     invoices.due_date,
                     invoices.net_total,
                     invoices.vat_total,
                     invoices.gross_total,
                     invoices.currency,
                     invoices.exchange_rate,
                     invoices.status,
                     invoices.payment_status,
                     (CASE WHEN ({$skipExpr}) = 1 THEN NULL ELSE ({$hufExpr}) END) AS gross_total_huf,
                     ({$this->invoiceDisplayStatusCaseSql()}) AS display_status"
                );

            if ($typeFilter === 'invoice') {
                $q->whereNull('invoices.storno_of_invoice_id');
            } elseif (in_array($typeFilter, ['invoice_storno', 'storno'], true)) {
                $q->whereNotNull('invoices.storno_of_invoice_id');
            }

            if ($search !== '') {
                $q->where(fn ($w) => $w
                    ->where('invoices.invoice_number', 'ilike', "%{$search}%")
                    ->orWhere('partners.name', 'ilike', "%{$search}%")
                );
            }

            if ($dateFrom !== '') {
                $q->whereDate('invoices.issue_date', '>=', $dateFrom);
            }
            if ($dateTo !== '') {
                $q->whereDate('invoices.issue_date', '<=', $dateTo);
            }
            if ($currency !== '') {
                $q->where('invoices.currency', $currency);
            }

            if ($statusFilter !== '') {
                $q = DB::query()->fromSub($q, 'x')->where('display_status', $statusFilter);
            }

            $parts[]  = "({$q->toSql()})";
            $bindings = array_merge($bindings, $q->getBindings());
        }

        if ($includeReceipts) {
            $hufExpr  = HufConversion::amountExpr('receipts.gross_total', 'receipts.currency', 'receipts.exchange_rate');
            $skipExpr = HufConversion::skipExpr('receipts.currency', 'receipts.exchange_rate');

            $q = Receipt::query()
                ->leftJoin('partners', 'partners.id', '=', 'receipts.partner_id')
                ->selectRaw(
                    "receipts.id,
                     'receipt' AS model_type,
                     CASE WHEN receipts.storno_of_receipt_id IS NOT NULL
                          THEN 'receipt_storno' ELSE 'receipt' END AS document_type,
                     receipts.receipt_number AS document_number,
                     partners.name AS partner_name,
                     partners.tax_number AS partner_tax_number,
                     receipts.issue_date,
                     receipts.fulfillment_date,
                     NULL::date AS due_date,
                     receipts.net_total,
                     receipts.vat_total,
                     receipts.gross_total,
                     receipts.currency,
                     receipts.exchange_rate,
                     receipts.status,
                     NULL AS payment_status,
                     (CASE WHEN ({$skipExpr}) = 1 THEN NULL ELSE ({$hufExpr}) END) AS gross_total_huf,
                     ({$this->receiptDisplayStatusCaseSql()}) AS display_status"
                );

            if ($typeFilter === 'receipt') {
                $q->whereNull('receipts.storno_of_receipt_id');
            } elseif (in_array($typeFilter, ['receipt_storno', 'storno'], true)) {
                $q->whereNotNull('receipts.storno_of_receipt_id');
            }

            if ($search !== '') {
                $q->where(fn ($w) => $w
                    ->where('receipts.receipt_number', 'ilike', "%{$search}%")
                    ->orWhere('partners.name', 'ilike', "%{$search}%")
                );
            }

            if ($dateFrom !== '') {
                $q->whereDate('receipts.issue_date', '>=', $dateFrom);
            }
            if ($dateTo !== '') {
                $q->whereDate('receipts.issue_date', '<=', $dateTo);
            }
            if ($currency !== '') {
                $q->where('receipts.currency', $currency);
            }

            if ($statusFilter !== '') {
                $q = DB::query()->fromSub($q, 'x')->where('display_status', $statusFilter);
            }

            $parts[]  = "({$q->toSql()})";
            $bindings = array_merge($bindings, $q->getBindings());
        }

        return [empty($parts) ? null : implode(' UNION ALL ', $parts), $bindings];
    }

    /**
     * Prioritási sorrend (l. feladat-leírás): sztornózott/sztornó > piszkozat >
     * fizetve > lejárt > részben fizetve > egyébként kiállított. A "mai nap"
     * Europe/Budapest szerint, DÁTUM-összehasonlítással — a határidő NAPJÁN
     * még nem lejárt, csak az azt követő naptól (l. HufConversion::todayBudapest(),
     * ugyanaz a minta, mint a ReportService receivablesAging()-jében).
     */
    private function invoiceDisplayStatusCaseSql(): string
    {
        $today = HufConversion::todayBudapest();

        return "CASE
            WHEN invoices.storno_of_invoice_id IS NOT NULL
                 OR EXISTS (SELECT 1 FROM invoices AS s WHERE s.storno_of_invoice_id = invoices.id)
                THEN 'storno'
            WHEN invoices.status = 'draft' THEN 'draft'
            WHEN invoices.payment_status = 'paid' THEN 'paid'
            WHEN invoices.payment_status = 'open' AND invoices.due_date < '{$today}'::date THEN 'overdue'
            WHEN invoices.payment_status = 'partial' THEN 'partially_paid'
            ELSE 'issued'
        END";
    }

    /**
     * A nyugtának nincs fizetési ciklusa (payment_status/due_date) — csak a
     * sztornó-ág releváns, minden más eset 'issued'-re esik.
     */
    private function receiptDisplayStatusCaseSql(): string
    {
        return "CASE
            WHEN receipts.storno_of_receipt_id IS NOT NULL
                 OR EXISTS (SELECT 1 FROM receipts AS s WHERE s.storno_of_receipt_id = receipts.id)
                THEN 'storno'
            ELSE 'issued'
        END";
    }
}
