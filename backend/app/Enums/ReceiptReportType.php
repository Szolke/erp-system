<?php

namespace App\Enums;

enum ReceiptReportType: string
{
    case Normal = 'normal';

    // D1: a beküldött jelentés immutábilis — utólagos változás NEM írja felül,
    // hanem egy 'correction' típusú jelentés jön létre, ami az eredetire
    // (mindig a nap NORMÁL jelentésére, nem láncolva) hivatkozik, a nap
    // TELJES újraszámolt összesítésével.
    case Correction = 'correction';
}
