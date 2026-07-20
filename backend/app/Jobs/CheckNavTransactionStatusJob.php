<?php

namespace App\Jobs;

use App\Models\CompanyNavCredential;
use App\Models\Invoice;
use App\Services\Nav\NavTransactionStatusChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The "convenience" half of the NAV verdict check (see
 * docs/nav-logging-audit.md phase 2) — dispatched once, with a 60s delay, right
 * after SendInvoiceToNavJob's successful branch, so a user gets a faster verdict
 * than waiting for the next 5-minute scheduled sweep. Deliberately a SINGLE
 * attempt: it does not reschedule itself on failure or on a still-pending
 * verdict — CheckNavSubmissionStatuses (the scheduled command) is the mechanism
 * actually relied upon to eventually resolve every submission.
 */
class CheckNavTransactionStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $invoiceId,
    ) {}

    public function handle(NavTransactionStatusChecker $checker): void
    {
        // withoutGlobalScope('company'): queue workers have no CurrentCompany
        // singleton — same reasoning as SendInvoiceToNavJob.
        $invoice = Invoice::withoutGlobalScope('company')
            ->with('company.navCredentials')
            ->find($this->invoiceId);

        if ($invoice === null || $invoice->nav_transaction_id === null) {
            return;
        }

        $credential = $invoice->company->navCredentials()
            ->where('environment', $invoice->company->nav_environment)
            ->where('is_active', true)
            ->first();

        if (! $credential instanceof CompanyNavCredential) {
            return;
        }

        $checker->check($invoice, $credential, $invoice->nav_transaction_id);
    }
}
