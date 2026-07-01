<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $user       = $request->user();
        $canInvoice = $user->can('invoice.view');
        $canReceipt = $user->can('receipt.view');

        if (! $canInvoice && ! $canReceipt) {
            $this->authorize('invoice.view'); // triggers 403 via Gate
        }

        $typeFilter = $request->string('type')->trim()->value();
        $search     = $request->string('search')->trim()->value();
        $dateFrom   = $request->string('date_from')->trim()->value();
        $dateTo     = $request->string('date_to')->trim()->value();
        $perPage    = $this->perPage($request);
        $page       = max(1, (int) $request->get('page', 1));

        // Determine which tables to query based on permission + type filter
        $includeInvoices = $canInvoice && in_array($typeFilter, ['', 'invoice', 'invoice_storno'], true);
        $includeReceipts = $canReceipt && in_array($typeFilter, ['', 'receipt', 'receipt_storno'], true);

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
                     invoices.status,
                     invoices.payment_status"
                );

            if ($typeFilter === 'invoice') {
                $q->whereNull('invoices.storno_of_invoice_id');
            } elseif ($typeFilter === 'invoice_storno') {
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
                     receipts.status,
                     NULL AS payment_status"
                );

            if ($typeFilter === 'receipt') {
                $q->whereNull('receipts.storno_of_receipt_id');
            } elseif ($typeFilter === 'receipt_storno') {
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

            $parts[]  = "({$q->toSql()})";
            $bindings = array_merge($bindings, $q->getBindings());
        }

        if (empty($parts)) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        $unionSql = implode(' UNION ALL ', $parts);

        $total = (int) DB::selectOne(
            "SELECT COUNT(*) AS total FROM ({$unionSql}) AS d",
            $bindings
        )->total;

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
        ]);
    }
}
