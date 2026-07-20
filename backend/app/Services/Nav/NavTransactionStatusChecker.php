<?php

namespace App\Services\Nav;

use App\Enums\NavStatus;
use App\Models\CompanyNavCredential;
use App\Models\Invoice;
use App\Models\NavSubmissionLog;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

/**
 * Resolves the NAV verdict for ONE already-submitted invoice via
 * queryTransactionStatus. Called both by CheckNavSubmissionStatuses (the 5-minute
 * scheduled guarantee) and CheckNavTransactionStatusJob (the 60s convenience
 * check after a successful send) — the logic must not be duplicated between them.
 *
 * NAV Online Számla 3.0 invoiceStatus values (queryTransactionStatus response,
 * see vendor/pzs/nav-online-invoice xsd/invoiceApi.xsd — NOT guessed):
 *   RECEIVED / PROCESSING / SAVED  — not final yet, invoice is left untouched
 *   DONE                           — final: accepted (see WARN handling below)
 *   ABORTED                        — final: rejected
 * technicalValidationMessages / businessValidationMessages each carry a
 * validationResultCode of ERROR / WARN / INFO (xsd/common.xsd BusinessResultCodeType).
 *
 * `status` on nav_submission_logs is the OUTCOME OF THIS HTTP CALL (success/error)
 * — NOT the NAV verdict. A "still PROCESSING" response is status=success with
 * processing_result='PROCESSING'. The status check constraint is intentionally
 * NOT extended for this — see docs/nav-logging-audit.md phase 2 section.
 */
class NavTransactionStatusChecker
{
    public function __construct(
        private readonly NavReporterFactory $reporterFactory,
    ) {}

    public function check(Invoice $invoice, CompanyNavCredential $credential, string $transactionId): void
    {
        $attemptNumber = NavSubmissionLog::where('invoice_id', $invoice->id)
            ->where('operation', 'queryTransactionStatus')
            ->count() + 1;

        $logBase = [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'attempt_number' => $attemptNumber,
            'operation' => 'queryTransactionStatus',
            'invoice_operation' => null,
            'environment' => $credential->environment->value,
            'transaction_id' => $transactionId,
            // No credential-free representation of this request exists in the vendor
            // lib (the request body IS the auth envelope + transactionId — nothing
            // else) — see docs/nav-logging-audit.md phase 2 section. Never fill this
            // from Reporter::getLastRequestData()/Connector::getLastRequestData():
            // both return the full envelope with login/passwordHash/requestSignature.
            'request_xml' => null,
        ];

        try {
            $reporter = $this->reporterFactory->make($credential);
            $responseXml = $reporter->queryTransactionStatus($transactionId);
        } catch (Throwable $e) {
            NavSubmissionLog::create([
                ...$logBase,
                'response_xml' => null,
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);

            // Intentionally NOT rethrown: this is a verification call, not the
            // submission itself. A transient failure here does not need a queue
            // retry — the next scheduled run (or the caller's own loop, for the
            // command) will pick this invoice up again regardless.
            return;
        }

        // Our invoices are always submitted one at a time (see NavXmlBuilder /
        // InvoiceOperations::convertFromXml — always exactly one invoice per
        // manageInvoice call), so the response always has exactly one
        // processingResult — never a batch of several.
        if (! isset($responseXml->processingResults->processingResult)) {
            NavSubmissionLog::create([
                ...$logBase,
                'response_xml' => $responseXml->asXML(),
                'status' => 'error',
                'error_message' => 'queryTransactionStatus response is missing processingResults',
            ]);

            return;
        }

        $processingResult = $responseXml->processingResults->processingResult;
        $invoiceStatus = (string) $processingResult->invoiceStatus;
        $messages = $this->extractValidationMessages($processingResult);

        NavSubmissionLog::create([
            ...$logBase,
            'response_xml' => $responseXml->asXML(),
            'status' => 'success',
            'processing_result' => $invoiceStatus,
            'validation_messages' => $messages,
        ]);

        $hasError = collect($messages)->contains(fn (array $m) => $m['severity'] === 'ERROR');
        $hasWarn = collect($messages)->contains(fn (array $m) => $m['severity'] === 'WARN');

        $terminalStatus = match (true) {
            $invoiceStatus === 'ABORTED' || $hasError => NavStatus::Rejected,
            $invoiceStatus === 'DONE' && $hasWarn => NavStatus::ConfirmedWithWarnings,
            $invoiceStatus === 'DONE' => NavStatus::Confirmed,
            default => null, // RECEIVED / PROCESSING / SAVED — no verdict yet
        };

        if ($terminalStatus !== null) {
            $invoice->update(['nav_status' => $terminalStatus]);
        }
    }

    /**
     * Abandons polling for a submission that has had no final verdict for 24h —
     * the give-up rule from docs/nav-logging-audit.md phase 2. No NAV call is
     * made; there is nothing left to log for an attempt that was never made.
     */
    public function abandon(Invoice $invoice): void
    {
        $invoice->update(['nav_status' => NavStatus::NeedsAttention]);

        Log::warning('NAV verification abandoned after 24h without a final result', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'transaction_id' => $invoice->nav_transaction_id,
        ]);
    }

    /**
     * @return array<int, array{source: string, severity: string, code: ?string, message: ?string}>
     */
    private function extractValidationMessages(SimpleXMLElement $processingResult): array
    {
        $messages = [];

        foreach ($processingResult->technicalValidationMessages as $m) {
            $messages[] = [
                'source' => 'technical',
                'severity' => (string) $m->validationResultCode,
                'code' => isset($m->validationErrorCode) ? (string) $m->validationErrorCode : null,
                'message' => isset($m->message) ? (string) $m->message : null,
            ];
        }

        foreach ($processingResult->businessValidationMessages as $m) {
            $messages[] = [
                'source' => 'business',
                'severity' => (string) $m->validationResultCode,
                'code' => isset($m->validationErrorCode) ? (string) $m->validationErrorCode : null,
                'message' => isset($m->message) ? (string) $m->message : null,
            ];
        }

        return $messages;
    }
}
