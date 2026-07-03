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

    /**
     * Deterministic storage path for an archived PDF.
     * Format: documents/{company_id}/{YYYY}/{MM}/{DD}/{number}.pdf
     * The document number (PREFIX-YYYYMM-000001) contains only A-Z, 0-9, '-' — safe on all filesystems.
     */
    public function storagePath(string $docNumber, int $companyId, \DateTimeInterface|string $issueDate): string
    {
        $d = $issueDate instanceof \DateTimeInterface ? $issueDate : \Carbon\Carbon::parse($issueDate);
        return sprintf('documents/%d/%s/%s.pdf', $companyId, $d->format('Y/m/d'), $docNumber);
    }

    /**
     * Archives the existing canonical PDF (if any) under a timestamped superseded name,
     * then regenerates the PDF from the current template and saves it at the canonical path.
     *
     * Returns the basename of the superseded file, or null if no prior file existed.
     * The superseded file is NEVER overwritten: a millisecond timestamp + collision counter
     * guarantee uniqueness even for rapid successive calls.
     */
    public function archiveAndRegenerateInvoice(Invoice $invoice): ?string
    {
        $path = $this->storagePath($invoice->invoice_number, $invoice->company_id, $invoice->issue_date);
        $supersededBasename = $this->archiveCurrentFile($path);
        Storage::disk('local')->put($path, $this->forInvoice($invoice)->output());
        return $supersededBasename;
    }

    /** @see archiveAndRegenerateInvoice */
    public function archiveAndRegenerateReceipt(Receipt $receipt): ?string
    {
        $path = $this->storagePath($receipt->receipt_number, $receipt->company_id, $receipt->issue_date);
        $supersededBasename = $this->archiveCurrentFile($path);
        Storage::disk('local')->put($path, $this->forReceipt($receipt)->output());
        return $supersededBasename;
    }

    /**
     * Saves the invoice PDF to the local disk immediately after issuance.
     * Skips silently if the file already exists — a legally archived PDF must never be overwritten.
     */
    public function persistInvoice(Invoice $invoice): void
    {
        $path = $this->storagePath($invoice->invoice_number, $invoice->company_id, $invoice->issue_date);
        if (!Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, $this->forInvoice($invoice)->output());
        }
    }

    /**
     * Saves the receipt PDF to the local disk immediately after issuance.
     * Skips silently if the file already exists — a legally archived PDF must never be overwritten.
     */
    public function persistReceipt(Receipt $receipt): void
    {
        $path = $this->storagePath($receipt->receipt_number, $receipt->company_id, $receipt->issue_date);
        if (!Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, $this->forReceipt($receipt)->output());
        }
    }

    /**
     * Moves the file at $canonicalPath to a timestamped superseded name in the same directory.
     * Returns the basename of the new superseded file, or null if no file existed.
     *
     * Collision guard: millisecond-precision timestamp makes collisions extremely unlikely;
     * the while-loop handles the residual edge case so the superseded file is NEVER overwritten.
     */
    private function archiveCurrentFile(string $canonicalPath): ?string
    {
        if (!Storage::disk('local')->exists($canonicalPath)) {
            return null;
        }

        $dir  = dirname($canonicalPath);
        $stem = pathinfo($canonicalPath, PATHINFO_FILENAME);

        $timestamp      = now()->format('YmdHis_v'); // e.g. 20260703143022_456
        $supersededPath = "{$dir}/{$stem}.superseded-{$timestamp}.pdf";

        $counter = 1;
        while (Storage::disk('local')->exists($supersededPath)) {
            $supersededPath = "{$dir}/{$stem}.superseded-{$timestamp}-{$counter}.pdf";
            $counter++;
        }

        Storage::disk('local')->move($canonicalPath, $supersededPath);

        return basename($supersededPath);
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
