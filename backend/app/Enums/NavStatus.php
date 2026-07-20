<?php

namespace App\Enums;

enum NavStatus: string
{
    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
    case Sent = 'sent';

    // The four terminal verdicts a queryTransactionStatus response can produce
    // (NavTransactionStatusChecker). 'Confirmed' existed before phase 2 but was
    // never actually set by any code path.
    case Confirmed = 'confirmed';
    case ConfirmedWithWarnings = 'confirmed_with_warnings';
    case Rejected = 'rejected';
    case NeedsAttention = 'needs_attention';

    case Error = 'error';
}
