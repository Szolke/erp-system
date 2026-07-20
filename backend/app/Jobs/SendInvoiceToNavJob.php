<?php

namespace App\Jobs;

use App\Enums\NavStatus;
use App\Models\Invoice;
use App\Models\NavSubmissionLog;
use App\Modules\ModuleResolver;
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

    public function handle(NavXmlBuilder $xmlBuilder, NavReporterFactory $reporterFactory, ModuleResolver $resolver): void
    {
        // withoutGlobalScope('company'): queue workers have no CurrentCompany singleton
        // set by EnsureCompanyContext middleware — the global scope must not filter here.
        $invoice = Invoice::withoutGlobalScope('company')
            ->with(['company.navCredentials', 'partner', 'paymentMethod', 'items.vatRate'])
            ->findOrFail($this->invoiceId);

        // Skip NAV submission when the nav module is disabled for this company.
        // Uses the invoice's own company_id — no CurrentCompany singleton in queue context.
        if (! $resolver->isAllowed('invoice.send_nav', $invoice->company_id)) {
            $invoice->update(['nav_status' => NavStatus::NotApplicable]);
            \Illuminate\Support\Facades\Log::info('NAV submission skipped: nav module disabled for company', [
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
            ]);

            return;
        }

        // Uses whichever environment the company is currently switched to
        // (companies.nav_environment) rather than just "the first active
        // credential" — a company can have both a test and a production
        // credential row at once, switching between them deliberately.
        $credential = $invoice->company->navCredentials()
            ->where('environment', $invoice->company->nav_environment)
            ->where('is_active', true)
            ->first();

        if ($credential === null) {
            $invoice->update(['nav_status' => NavStatus::NotApplicable]);

            // NAV modul BE van kapcsolva, de az aktív environmenthez nincs is_active=true
            // credential → a számla NÉMÁN nem menne ki. Warning jelzi az operátornak.
            \Illuminate\Support\Facades\Log::warning('NAV submission skipped: no active credential for environment', [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'company_id'     => $invoice->company_id,
                'environment'    => $invoice->company->nav_environment?->value,
            ]);

            return;
        }

        $attemptNumber = NavSubmissionLog::where('invoice_id', $invoice->id)->count() + 1;
        $requestXml = null;
        $responseXml = null;
        $transactionId = null;

        // Common fields for the nav_submission_logs row, filled on BOTH the success
        // and the error branch below — reconstructing a failed submission requires
        // knowing the company/operation/environment even when it never got a
        // transactionId back. company_id comes from the invoice, not CurrentCompany
        // (queue context — see NavSubmissionLog docblock).
        $logBase = [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'attempt_number' => $attemptNumber,
            'operation' => 'manageInvoice',
            'invoice_operation' => $this->operation,
            'environment' => $credential->environment->value,
        ];

        try {
            $xmlElement = $xmlBuilder->build($invoice);
            $requestXml = $xmlElement->asXML();

            $reporter = $reporterFactory->make($credential);
            $transactionId = $reporter->manageInvoice($xmlElement, $this->operation);

            $responseXml = 'transactionId: '.$transactionId;

            NavSubmissionLog::create([
                ...$logBase,
                'transaction_id' => $transactionId,
                'request_xml' => $requestXml,
                'response_xml' => $responseXml,
                'status' => 'success',
            ]);

            $invoice->update([
                'nav_status' => NavStatus::Sent,
                'nav_transaction_id' => $transactionId,
                'nav_sent_at' => now(),
            ]);

            // The "convenience" verdict check (see docs/nav-logging-audit.md phase 2)
            // — a single attempt, not rescheduled if it fails. The scheduled
            // nav:check-submission-status command is the mechanism actually relied
            // upon to resolve every submission; this just makes the common case fast.
            CheckNavTransactionStatusJob::dispatch($invoice->id)->delay(now()->addSeconds(60));

        } catch (Throwable $e) {
            NavSubmissionLog::create([
                ...$logBase,
                'transaction_id' => $transactionId,
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
