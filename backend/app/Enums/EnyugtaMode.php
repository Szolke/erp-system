<?php

namespace App\Enums;

enum EnyugtaMode: string
{
    // Végigfuttatja a teljes pipeline-t (validáció, naplózás, archiválás), de
    // HTTP-hívás helyett fix, séma-konform választ ad vissza — ez teszi
    // CI-ban tesztelhetővé a modult NAV-kapcsolat nélkül (l.
    // docs/nav-enyugta-spec-jegyzetek.md 3. fejezet: nincs megerősített
    // sandbox-elérhetőség).
    case Mock = 'mock';
    case Test = 'test';
    case Live = 'live';
}
