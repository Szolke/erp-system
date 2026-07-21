<?php

namespace App\Services\Enyugta;

use RuntimeException;

/**
 * D2 (nyugta-zárolás, NAV eNyugta 2. fázis): egyszer jelentett nyugta
 * (`reported_at` kitöltve) nem módosítható és nem törölhető. A Receipt
 * modell saving/deleting eseményre kötött guardja dobja — l. Receipt::booted().
 */
class ReceiptAlreadyReportedException extends RuntimeException {}
