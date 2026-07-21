<?php

namespace App\Enums;

enum ReceiptReportStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';

    // A 'sending'/'accepted'/'rejected' értékeket a 3. fázis (tényleges NAV
    // beküldés) fogja ténylegesen beállítani — már most felvéve, hogy ne
    // kelljen később enum-bővítő migráció (l. migráció doc-komment).
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
