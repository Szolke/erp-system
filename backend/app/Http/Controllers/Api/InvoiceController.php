<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/**
 * Invoices are immutable once issued — there is no update endpoint.
 * Cancellation is handled by the dedicated 'cancel' action which creates
 * a storno (credit note) document via InvoiceService::cancel().
 *
 * The draft-then-issue workflow is not implemented in this phase (v1 always
 * issues immediately). If gap-free numbering while supporting abandoned drafts
 * becomes a requirement, a nullable invoice_number + explicit /issue action
 * should be added.
 */
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoiceService) {}

    public function index(Request $request)
    {
        $this->authorize('invoice.view');

        $invoices = Invoice::query()
            ->with('partner')
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();
                $query->where(fn ($q) => $q->where('invoice_number', 'ilike', "%{$search}%")
                    ->orWhereHas('partner', fn ($q2) => $q2->where('name', 'ilike', "%{$search}%")));
            })
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return InvoiceResource::collection($invoices);
    }

    public function store(StoreInvoiceRequest $request, CurrentCompany $currentCompany)
    {
        $company = Company::findOrFail($currentCompany->id());
        $invoice = $this->invoiceService->create($company, $request->validated(), $request->user());

        return InvoiceResource::make($invoice)->response()->setStatusCode(201);
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('invoice.view');

        return InvoiceResource::make(
            $invoice->load(['items.vatRate', 'items.product', 'partner', 'paymentMethod'])
        );
    }

    public function cancel(Invoice $invoice, Request $request)
    {
        $this->authorize('invoice.cancel');

        $storno = $this->invoiceService->cancel($invoice, $request->user());

        return InvoiceResource::make($storno)->response()->setStatusCode(201);
    }
}
