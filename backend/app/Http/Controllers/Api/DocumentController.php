<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Support\HufConversion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Bizonylatok
 *
 * A számla és nyugta (+ sztornóik) UNION ALL-lal egyesített listája. A
 * `display_status` egy SQL-ben SZÁRMAZTATOTT (nem tárolt) mező — l.
 * invoiceDisplayStatusCaseSql()/receiptDisplayStatusCaseSql() a pontos
 * prioritási sorrendért. A `summary` blokk a TELJES szűrt halmazra számol
 * (nem csak az aktuális oldalra), HUF-ra normalizálva — ugyanazt a
 * HufConversion helpert használja, mint a ReportService (Kimutatások).
 */
class DocumentController extends Controller
{
    /** display_status értékek, amik kizárólag számlán fordulhatnak elő — ha ez a szűrt
     *  státusz, a nyugta-ágat egyáltalán nem érdemes lekérdezni. */
    private const INVOICE_ONLY_STATUSES = ['draft', 'paid', 'overdue', 'partially_paid'];

    public function index(Request $request)
    {
        $user       = $request->user();
        $canInvoice = $user->can('invoice.view');
        $canReceipt = $user->can('receipt.view');

        if (! $canInvoice && ! $canReceipt) {
            $this->authorize('invoice.view'); // triggers 403 via Gate
        }

        $typeFilter    = $request->string('type')->trim()->value();
        $statusFilter  = $request->string('status')->trim()->value();
        $search        = $request->string('search')->trim()->value();
        $dateFrom      = $request->string('date_from')->trim()->value();
        $dateTo        = $request->string('date_to')->trim()->value();
        $currency      = $request->string('currency')->trim()->value();
        $perPage       = $this->perPage($request);
        $page          = max(1, (int) $request->get('page', 1));

        $statusExcludesReceipts = $statusFilter !== '' && in_array($statusFilter, self::INVOICE_ONLY_STATUSES, true);

        // 'storno' egy ÖSSZEVONT típus-fül: számla- ÉS nyugta-sztornók együtt.
        $includeInvoices = $canInvoice && in_array($typeFilter, ['', 'invoice', 'invoice_storno', 'storno'], true);
        $includeReceipts = $canReceipt
            && in_array($typeFilter, ['', 'receipt', 'receipt_storno', 'storno'], true)
            && ! $statusExcludesReceipts;

        $parts    = [];
        $bindings = [];

        if ($includeInvoices) {
            $q = Invoice::query()
                ->leftJoin('partners', 'partners.id', '=', 'invoices.partner_id')
                ->selectRaw(
                    "invoices.id,
                     'invoice' AS model_type,
                     CASE WHEN invoices.storno_of_invoice_id IS NOT NULL
                          THEN 'invoice_storno' ELSE 'invoice' END AS document_type,
                     invoices.invoice_number AS document_number,
                     partners.name AS partner_name,
                     invoices.issue_date,
                     invoices.gross_total,
                     invoices.currency,
                     invoices.exchange_rate,
                     invoices.status,
                     invoices.payment_status,
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
            $q = Receipt::query()
                ->leftJoin('partners', 'partners.id', '=', 'receipts.partner_id')
                ->selectRaw(
                    "receipts.id,
                     'receipt' AS model_type,
                     CASE WHEN receipts.storno_of_receipt_id IS NOT NULL
                          THEN 'receipt_storno' ELSE 'receipt' END AS document_type,
                     receipts.receipt_number AS document_number,
                     partners.name AS partner_name,
                     receipts.issue_date,
                     receipts.gross_total,
                     receipts.currency,
                     receipts.exchange_rate,
                     receipts.status,
                     NULL AS payment_status,
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

        if (empty($parts)) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'summary' => ['count' => 0, 'gross_total_huf' => 0.0, 'skipped_count' => 0],
            ]);
        }

        $unionSql = implode(' UNION ALL ', $parts);

        $total = (int) DB::selectOne(
            "SELECT COUNT(*) AS total FROM ({$unionSql}) AS d",
            $bindings
        )->total;

        $skip     = HufConversion::skipExpr('currency', 'exchange_rate');
        $grossHuf = HufConversion::amountExpr('gross_total', 'currency', 'exchange_rate');
        $summary  = DB::selectOne(
            "SELECT
                COUNT(*) AS doc_count,
                COALESCE(SUM(CASE WHEN {$skip} = 0 THEN {$grossHuf} ELSE 0 END), 0) AS gross_total_huf,
                COALESCE(SUM({$skip}), 0) AS skipped_count
             FROM ({$unionSql}) AS d",
            $bindings
        );

        $offset = ($page - 1) * $perPage;
        $rows   = DB::select(
            "SELECT * FROM ({$unionSql}) AS d ORDER BY issue_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}",
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
