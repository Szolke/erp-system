<?php

namespace App\Enums;

enum DocumentType: string
{
    case Invoice       = 'invoice';
    case Receipt       = 'receipt';
    case InvoiceStorno = 'invoice_storno';
    case ReceiptStorno = 'receipt_storno';

    public function label(): string
    {
        return match($this) {
            self::Invoice       => 'Számla',
            self::Receipt       => 'Nyugta',
            self::InvoiceStorno => 'Sztornó számla',
            self::ReceiptStorno => 'Sztornó nyugta',
        };
    }
}
