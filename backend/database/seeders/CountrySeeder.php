<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

/**
 * ISO 3166-1 alpha-2 országkódok feltöltése a `config('countries')` alapján —
 * csak a hiányzó kódokat szúrja be, a meglévő `enabled` állapotot SOSEM írja
 * felül (superadmin be/kikapcsolása újrafuttatás után is megmarad), és
 * semmit nem töröl. Mindig fusson (l. `DatabaseSeeder`), hogy friss
 * telepítésnél a frontend ország-legördülői (partner számlázási cím,
 * `settings/countries` admin-UI stb.) ne maradjanak üresen.
 *
 * Az `erp:sync-countries` konzolparancs erre a seederre épül (részletes
 * riportozással kézi újrafuttatáshoz) — a beszúrás logikája egy helyen él.
 */
class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $configCodes = config('countries');
        $existingCodes = Country::pluck('code')->all();

        $missingCodes = array_diff($configCodes, $existingCodes);
        foreach ($missingCodes as $code) {
            Country::create(['code' => $code, 'enabled' => true]);
        }
    }
}
