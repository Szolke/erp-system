<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Invoice;

/**
 * Recalculates an invoice's payment_status from the sum of its recorded
 * payments — used both by manual payment recording and the SimplePay IPN
 * handler so the two paths can't drift apart.
 */
class PaymentStatusUpdater
{
    public function recalculate(Invoice $invoice): void
    {
        $paid = (float) $invoice->payments()->sum('amount');
        $total = (float) $invoice->gross_total;

        $status = match (true) {
            $paid <= 0 => PaymentStatus::Open,
            $paid >= $total => PaymentStatus::Paid,
            default => PaymentStatus::Partial,
        };

        $invoice->update(['payment_status' => $status]);
    }
}
