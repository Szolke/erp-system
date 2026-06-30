<?php

namespace App\Enums;

enum NavStatus: string
{
    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
    case Sent = 'sent';
    case Confirmed = 'confirmed';
    case Error = 'error';
}
