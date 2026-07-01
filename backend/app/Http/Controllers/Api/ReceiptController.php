<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReceiptRequest;
use App\Http\Resources\ReceiptResource;
use App\Models\Company;
use App\Models\Receipt;
use App\Services\ReceiptService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function __construct(private ReceiptService $receiptService) {}

    public function index(Request $request)
    {
        $this->authorize('receipt.view');

        $receipts = Receipt::query()
            ->with('partner')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ReceiptResource::collection($receipts);
    }

    public function store(StoreReceiptRequest $request, CurrentCompany $currentCompany)
    {
        $company = Company::findOrFail($currentCompany->id());
        $receipt = $this->receiptService->create($company, $request->validated(), $request->user());

        return ReceiptResource::make($receipt)->response()->setStatusCode(201);
    }

    public function show(Receipt $receipt)
    {
        $this->authorize('receipt.view');

        return ReceiptResource::make(
            $receipt->load(['items.vatRate', 'items.product', 'partner', 'paymentMethod'])
        );
    }

    public function cancel(Receipt $receipt, Request $request)
    {
        $this->authorize('receipt.cancel');

        $storno = $this->receiptService->cancel($receipt, $request->user());

        return ReceiptResource::make($storno)->response()->setStatusCode(201);
    }
}
