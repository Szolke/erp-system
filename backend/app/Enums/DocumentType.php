<?php

namespace App\Enums;

enum DocumentType: string
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
}
