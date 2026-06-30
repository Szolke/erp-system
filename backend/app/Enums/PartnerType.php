<?php

namespace App\Enums;

enum PartnerType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';
}
