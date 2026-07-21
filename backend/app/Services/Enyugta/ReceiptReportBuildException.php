<?php

namespace App\Services\Enyugta;

use RuntimeException;

/**
 * A napi nyugta-összesítő nem generálható a jelenlegi adatokkal — pl. D4
 * (hiányzó vat_rates.nav_receipt_category megfeleltetés) vagy nem-HUF nyugta.
 * Szándékosan éles hiba, nem néma kihagyás vagy tippelés — l.
 * ReceiptReportBuilder.
 */
class ReceiptReportBuildException extends RuntimeException {}
