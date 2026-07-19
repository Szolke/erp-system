<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Megosztott SQL-kifejezés-builderek a HUF-normalizáláshoz — a ReportService
 * (Kimutatások) és a DocumentController (bizonylatlista-összesítő) egyaránt
 * ugyanazt a szabályt alkalmazza: HUF-on nincs konverzió, egyébként a
 * bizonylaton tárolt exchange_rate-tel szorzunk; hiányzó/érvénytelen
 * árfolyamú, nem-HUF sor kimarad az összegből, de a warnings/skipped_count-ba
 * beleszámít (l. ReportService osztály-docblock 3. pontja a teljes indoklásért).
 */
class HufConversion
{
    public static function amountExpr(string $amountExpr, string $currencyCol, string $rateCol): string
    {
        return "CASE WHEN {$currencyCol} = 'HUF' THEN {$amountExpr} ELSE {$amountExpr} * {$rateCol} END";
    }

    public static function skipExpr(string $currencyCol, string $rateCol): string
    {
        return "CASE WHEN {$currencyCol} <> 'HUF' AND ({$rateCol} IS NULL OR {$rateCol} <= 0) THEN 1 ELSE 0 END";
    }

    /** "Ma", Europe/Budapest szerint — az app config('app.timezone')-ja UTC. */
    public static function todayBudapest(): string
    {
        return Carbon::now('Europe/Budapest')->toDateString();
    }
}
