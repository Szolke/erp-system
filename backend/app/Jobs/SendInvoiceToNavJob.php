<?php

namespace App\Jobs;

use App\Enums\NavStatus;
use App\Models\Invoice;
use App\Models\NavSubmissionLog;
use App\Services\Nav\NavReporterFactory;
use App\Services\Nav\NavXmlBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Submits (or re-submits) an invoice to the NAV Online Számla API.
 * Always runs asynchronously — never blocks a user-facing request.
 * Each attempt is logged to nav_submission_logs for full audit trail.
 */
class SendInvoiceToNavJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 300; // 5 minutes between retries

    public function __construct(
        private readonly int $invoiceId,
        private readonly string $operation = 'CREATE',
    ) {}

    public function handle(NavXmlBuilder $xmlBuilder, NavReporterFactory $reporterFactory): void
    {
        $invoice = Invoice::with(['company.navCredentials', 'partner', 'paymentMethod', 'items.vatRate'])
            ->findOrFail($this->invoiceId);

        $credential = $invoice->company->navCredentials()
            ->where('is_active', true)
            ->first();

        if ($credential === null) {
            $invoice->update(['nav_status' => NavStatus::NotApplicable]);

            return;
        }

        $attemptNumber = NavSubmissionLog::where('invoice_id', $invoice->id)->count() + 1;
        $requestXml = null;
        $responseXml = null;

        try {
            $xmlElement = $xmlBuilder->build($invoice);
            $requestXml = $xmlElement->asXML();

            $reporter = $reporterFactory->make($credential);
            $transactionId = $reporter->manageInvoice($xmlElement, $this->operation);

            $responseXml = 'transactionId: '.$transactionId;

            NavSubmissionLog::create([
                'invoice_id' => $invoice->id,
                'attempt_number' => $attemptNumber,
                'request_xml' => $requestXml,
                'response_xml' => $responseXml,
                'status' => 'success',
            ]);

            $invoice->update([
                'nav_status' => NavStatus::Sent,
                'nav_transaction_id' => $transactionId,
                'nav_sent_at' => now(),
            ]);

        } catch (Throwable $e) {
            NavSubmissionLog::create([
                'invoice_id' => $invoice->id,
                'attempt_number' => $attemptNumber,
                'request_xml' => $requestXml,
                'response_xml' => $responseXml,
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);

            $invoice->update(['nav_status' => NavStatus::Error]);

            throw $e; // re-throw so the queue retries (up to $tries)
        }
    }
}
