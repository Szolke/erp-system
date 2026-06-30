<?php

namespace App\Enums;

enum ReceiptStatus: string
{
    case Issued = 'issued';
    case Storno = 'storno';
}
