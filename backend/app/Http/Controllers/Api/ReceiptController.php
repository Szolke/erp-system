<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReceiptRequest;
use App\Http\Resources\ReceiptResource;
use App\Models\Company;
use App\Models\Receipt;
use App\Services\PdfService;
use App\Services\ReceiptService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @group Nyugták */
class ReceiptController extends Controller
{
    use EnforcesCompanyScope;

    public function __construct(
        private ReceiptService $receiptService,
        private PdfService $pdfService,
    ) {}

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
        $this->assertBelongsToCurrentCompany($receipt);
        $this->authorize('receipt.view');

        return ReceiptResource::make(
            $receipt->load(['items.vatRate', 'items.product', 'partner', 'paymentMethod'])
        );
    }

    public function cancel(Receipt $receipt, Request $request)
    {
        $this->assertBelongsToCurrentCompany($receipt);
        $this->authorize('receipt.cancel');

        $storno = $this->receiptService->cancel($receipt, $request->user());

        return ReceiptResource::make($storno)->response()->setStatusCode(201);
    }

    /** GET /api/receipts/{receipt}/pdf — on-the-fly PDF letöltés */
    /** GET /api/receipts/{receipt}/pdf — archivált vagy on-the-fly PDF letöltés */
    public function pdf(Receipt $receipt): StreamedResponse
    {
        $this->assertBelongsToCurrentCompany($receipt);
        $this->authorize('receipt.view');

        $path     = $this->pdfService->storagePath($receipt->receipt_number, $receipt->company_id, $receipt->issue_date);
        $filename = $receipt->receipt_number . '.pdf';

        if (Storage::disk('local')->exists($path)) {
            return response()->streamDownload(
                fn () => print(Storage::disk('local')->get($path)),
                $filename,
                ['Content-Type' => 'application/pdf'],
            );
        }

        // Fallback: régi bizonylat vagy kiállításkori mentési kudarc esetén generál + ment.
        // Meglévő fájlt sosem ír felül (az exists() ellenőrzés fent garantálja).
        $this->pdfService->persistReceipt($receipt);

        return response()->streamDownload(
            fn () => print(Storage::disk('local')->get($path)),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
