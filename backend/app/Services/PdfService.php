<?php

namespace App\Services;

use App\Enums\CompanySetting;
use App\Models\Invoice;
use App\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PdfService
{
    public function __construct(private CompanySettingService $settings) {}

    public function forInvoice(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->loadMissing(['company', 'partner', 'paymentMethod', 'items.vatRate', 'stornoOf']);

        $lang = $this->settings->get($invoice->company_id, CompanySetting::INVOICE_LANGUAGE);
        app()->setLocale($lang);

        return Pdf::loadView('pdf.invoice', [
            'invoice'  => $invoice,
            'company'  => $invoice->company,
            'logoData' => $this->logoBase64($invoice->company->logo_path),
        ])->setPaper('A4', 'portrait');
    }

    public function forReceipt(Receipt $receipt): \Barryvdh\DomPDF\PDF
    {
        $receipt->loadMissing(['company', 'partner', 'paymentMethod', 'items.vatRate', 'stornoOf']);

        $lang = $this->settings->get($receipt->company_id, CompanySetting::INVOICE_LANGUAGE);
        app()->setLocale($lang);

        return Pdf::loadView('pdf.receipt', [
            'receipt'  => $receipt,
            'company'  => $receipt->company,
            'logoData' => $this->logoBase64($receipt->company->logo_path),
        ])->setPaper('A4', 'portrait');
    }

    private function logoBase64(?string $logoPath): ?string
    {
        if (!$logoPath) {
            return null;
        }

        $abs = Storage::disk('public')->path($logoPath);
        if (!file_exists($abs)) {
            return null;
        }

        $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'image/png',
        };

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($abs));
    }
}
