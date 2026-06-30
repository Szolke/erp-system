<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
}
