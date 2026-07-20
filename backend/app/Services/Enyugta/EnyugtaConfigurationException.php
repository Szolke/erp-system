<?php

namespace App\Services\Enyugta;

use RuntimeException;

/**
 * Konfigurációs hiba (pl. hiányzó base URL test/live módban) — szándékosan
 * elkülönítve az általános RuntimeException-től, hogy a hívók (pl.
 * erp:sync-enyugta-vat-categories) tiszta hibaüzenettel, exit code 1-gyel
 * tudjanak kilépni, ne exception-nel/stack trace-szel.
 */
class EnyugtaConfigurationException extends RuntimeException {}
