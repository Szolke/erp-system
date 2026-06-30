<?php

namespace Database\Seeders;

use App\Models\VatRate;
use Illuminate\Database\Seeder;

/**
 * Hungarian VAT rates. The percentage-based rows (27/18/5/0%) are stable,
 * well-documented Áfa törvény values.
 *
 * The exemption-category rows (AAM, TAM) use mnemonics that are part of the
 * NAV Online Számla "vatExemption" enumeration as of general knowledge, but
 * have NOT been verified against the current NAV Online Számla 3.0 XSD —
 * do that verification before this seeder's nav_code values are relied on
 * for actual NAV XML submission (see docs/er-model.md, NAV integration step).
 */
class VatRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            ['name' => '27% normál', 'rate_percent' => 27.00, 'nav_code' => '0.27'],
            ['name' => '18% kedvezményes', 'rate_percent' => 18.00, 'nav_code' => '0.18'],
            ['name' => '5% kedvezményes', 'rate_percent' => 5.00, 'nav_code' => '0.05'],
            ['name' => '0%', 'rate_percent' => 0.00, 'nav_code' => '0.00'],
            ['name' => 'Alanyi adómentes (AAM)', 'rate_percent' => null, 'nav_code' => 'AAM'],
            ['name' => 'Tárgyi adómentes (TAM)', 'rate_percent' => null, 'nav_code' => 'TAM'],
        ];

        foreach ($rates as $rate) {
            VatRate::query()->updateOrCreate(['nav_code' => $rate['nav_code']], $rate);
        }
    }
}
