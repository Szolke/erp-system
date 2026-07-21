<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A napi jelentés áfa-kategóriánkénti bontása. NINCS company_id és NINCS
 * BelongsToCompany — a `receipt_items` mintáját követi: a szülőn
 * (ReceiptReport, ami cégre szűrt) keresztül van scope-olva.
 *
 * A sor csoportosítási kulcsa KIZÁRÓLAG a `nav_receipt_category` (a NAV
 * eNyugta kategória NEVE) — a belső áfakulcsra (`vat_rates`) SZÁNDÉKOSAN
 * nincs hivatkozás: (a) a jelentés immutábilis jogi rekord (D1), nem
 * korlátozhatja a mutálható törzsadat (`vat_rates`) törölhetőségét egy
 * `restrictOnDelete` FK-val; (b) egy néha NULL, néha kitöltött FK (ha több
 * áfakulcs is ugyanarra a NAV-kategóriára képeződik) félrevezető, redundáns
 * adat lenne. L. database/migrations/2026_07_20_000008_..._table.php.
 */
#[Fillable(['receipt_report_id', 'nav_receipt_category', 'net_amount', 'vat_amount', 'gross_amount', 'receipt_count'])]
class ReceiptReportLine extends Model
{
    protected function casts(): array
    {
        return [
            'net_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'receipt_count' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ReceiptReport::class, 'receipt_report_id');
    }
}
