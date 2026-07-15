<?php

namespace App\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case Issued = 'issued';
    case Service = 'service';
    case Scrapped = 'scrapped';
}
