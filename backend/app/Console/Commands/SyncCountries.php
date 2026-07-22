<?php

namespace App\Console\Commands;

use App\Models\Country;
use Database\Seeders\CountrySeeder;
use Illuminate\Console\Command;

class SyncCountries extends Command
{
    protected $signature = 'erp:sync-countries';

    /**
     * Beszúrja a config('countries')-ban szereplő, de a `countries` táblából még
     * hiányzó kódokat (enabled=true default-tal) — a tényleges beszúrás-logika a
     * `CountrySeeder`-ben él (az mindig lefut a `DatabaseSeeder`-ből is), ez a
     * parancs csak kézi újrafuttatáshoz ad részletes riportot ugyanarra a
     * műveletre. A már létező sorok `enabled` állapotát SOSEM írja felül — a
     * superadmin által beállított be/kikapcsolt állapot deploy/újrafuttatás után
     * is megmarad. A táblában lévő, de configból eltűnt kódokat nem törli, csak
     * jelzi.
     */
    protected $description = 'Insert missing country codes from config/countries.php into the countries table (never overwrites enabled state, never deletes).';

    public function handle(): int
    {
        $configCodes = config('countries');
        $existingCodes = Country::pluck('code')->all();

        (new CountrySeeder())->run();

        $missingCodes = array_diff($configCodes, $existingCodes);
        $staleCodes = array_diff($existingCodes, $configCodes);

        $this->printSection('Létrehozva', array_values($missingCodes), 'info');
        $this->printSection('DB-ben van, de a configból hiányzik (nem törölve)', array_values($staleCodes), 'warn');

        $this->newLine();
        $this->info(sprintf(
            'Összesen: %d új | %d meglévő (érintetlen) | %d config-ból hiányzó',
            count($missingCodes),
            count($existingCodes) - count($staleCodes),
            count($staleCodes),
        ));

        return self::SUCCESS;
    }

    private function printSection(string $label, array $codes, string $level): void
    {
        if (empty($codes)) {
            return;
        }

        $method = $level === 'warn' ? 'warn' : $level;
        $this->$method("{$label} (" . count($codes) . ')');
        $this->table(['Kód'], array_map(fn ($c) => [$c], $codes));
    }
}
