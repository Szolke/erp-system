<?php

namespace App\Console\Commands;

use App\Models\CompanyNavCredential;
use App\Models\Invoice;
use App\Services\Nav\NavTransactionStatusChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The GUARANTEE half of the NAV verdict check (see docs/nav-logging-audit.md
 * phase 2) — the only mechanism actually relied upon to eventually resolve
 * every submission. Self-healing: if a worker was down or a
 * CheckNavTransactionStatusJob was lost, the next run of this command picks the
 * invoice up regardless, because it queries the current DB state rather than
 * reacting to an event. Scheduled every 5 minutes (routes/console.php).
 *
 * Runs across ALL companies in one process — there is no HTTP request here, so
 * no CurrentCompany singleton is ever set. Every company-scoped lookup below is
 * therefore explicit (withoutGlobalScope('company'), credential looked up per
 * company_id) — the same failure class SendInvoiceToNavJob already had to avoid.
 */
class CheckNavSubmissionStatuses extends Command
{
    protected $signature = 'nav:check-submission-status {--invoice= : Check a single invoice id manually, bypassing the 24h give-up cutoff}';

    protected $description = 'Poll NAV queryTransactionStatus for invoices awaiting a final verdict, and resolve or abandon them.';

    public function handle(NavTransactionStatusChecker $checker): int
    {
        if ($invoiceId = $this->option('invoice')) {
            return $this->checkSingleInvoice($checker, (int) $invoiceId);
        }

        $invoices = Invoice::withoutGlobalScope('company')
            ->with('company.navCredentials')
            ->awaitingNavVerification()
            ->get();

        $giveUpCutoff = now()->subDay();

        foreach ($invoices->groupBy('company_id') as $companyId => $companyInvoices) {
            try {
                $company = $companyInvoices->first()->company;

                $credential = $company->navCredentials()
                    ->where('environment', $company->nav_environment)
                    ->where('is_active', true)
                    ->first();

                if (! $credential instanceof CompanyNavCredential) {
                    // The nav module/credential could have been disabled or removed
                    // after these invoices were sent — nothing to poll them with.
                    // Leave them as-is; a human already has to look at the company's
                    // NAV settings regardless of what we do here.
                    continue;
                }

                foreach ($companyInvoices as $invoice) {
                    if ($invoice->nav_sent_at->lt($giveUpCutoff)) {
                        $checker->abandon($invoice);

                        continue;
                    }

                    $checker->check($invoice, $credential, $invoice->nav_transaction_id);
                }
            } catch (Throwable $e) {
                // One company's failure (e.g. credential lookup blew up) must not
                // abort the whole sweep — the remaining companies still need theirs.
                Log::error('NAV submission status sweep failed for company', [
                    'company_id' => $companyId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Manual, single-invoice check for debugging — deliberately bypasses the 24h
     * give-up cutoff (the whole point of a manual check is often to re-check
     * something that already timed out).
     */
    private function checkSingleInvoice(NavTransactionStatusChecker $checker, int $invoiceId): int
    {
        $invoice = Invoice::withoutGlobalScope('company')
            ->with('company.navCredentials')
            ->find($invoiceId);

        if ($invoice === null) {
            $this->error("Invoice {$invoiceId} not found.");

            return self::FAILURE;
        }

        if ($invoice->nav_transaction_id === null) {
            $this->error("Invoice {$invoiceId} has no nav_transaction_id — it was never successfully sent to NAV.");

            return self::FAILURE;
        }

        $credential = $invoice->company->navCredentials()
            ->where('environment', $invoice->company->nav_environment)
            ->where('is_active', true)
            ->first();

        if (! $credential instanceof CompanyNavCredential) {
            $this->error("No active NAV credential for invoice {$invoiceId}'s company/environment.");

            return self::FAILURE;
        }

        $checker->check($invoice, $credential, $invoice->nav_transaction_id);

        $invoice->refresh();
        $this->info("Checked invoice {$invoiceId} — nav_status is now: {$invoice->nav_status->value}");

        return self::SUCCESS;
    }
}
