<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tömeges teszt-felhasználók egy céghez — a nagy user-bázisú felületek
 * (elsősorban az értékesítő csoportok tagválasztója) kézi végigjátszásához.
 *
 * MIÉRT KELL: a tagválasztó korábban egyetlen `per_page: 200` lövéssel töltötte
 * a jelölteket, ezért a névsor 200. helye utáni felhasználó egyáltalán nem volt
 * kiválasztható. A szerver-oldali kereséssel ez megszűnt, de a regressziót csak
 * 200-nál TÖBB felhasználóval lehet igazolni — ez a seeder állítja elő azt a
 * terepet, reprodukálhatóan.
 *
 * TULAJDONSÁGOK:
 * - Csak `local` / `testing` környezetben fut (a DemoDataSeeder guard-mintája).
 * - Idempotens: e-mail alapú `firstOrCreate`, újrafuttatás nem duplikál.
 * - Felismerhető: minden e-mail a `loadtest+NNN@demo.local` alakot követi, így
 *   a takarítás egyetlen `where('email', 'like', 'loadtest+%@demo.local')`.
 * - A jelszó eldobható véletlen érték: ezek a fiókok NEM bejelentkezésre
 *   valók, csak azért léteznek, hogy legyen mit listázni és keresni.
 *
 * HASZNÁLAT:
 *   php artisan db:seed --class=BulkTestUsersSeeder
 *   COUNT=500 COMPANY="Teszt Bt." php artisan db:seed --class=BulkTestUsersSeeder
 *
 * TAKARÍTÁS (l. a docs-ban is): a `loadtest+` prefixű userek törlése.
 */
class BulkTestUsersSeeder extends Seeder
{
    /** Hány teszt-user készüljön, ha a COUNT env nincs megadva. */
    private const DEFAULT_COUNT = 300;

    /** Melyik cégbe, ha a COMPANY env nincs megadva. */
    private const DEFAULT_COMPANY = 'Demo Kft.';

    /** Az e-mail-prefix, ami a teszt-usereket felismerhetővé és törölhetővé teszi. */
    public const EMAIL_PREFIX = 'loadtest+';

    /**
     * Vezetéknevek A-tól Zs-ig. A generálás ezeken körbe-körbe jár, így a nevek
     * egyenletesen töltik ki az ábécét — a lista végén lévő „Zs" kezdetűek
     * garantáltan a névsor 200. helye UTÁN landolnak 300 usernél.
     */
    private const SURNAMES = [
        'Antal', 'Balogh', 'Csáki', 'Dobos', 'Erdős', 'Farkas', 'Gál', 'Halász',
        'Illés', 'Jakab', 'Kovács', 'Lakatos', 'Molnár', 'Nagy', 'Orosz', 'Papp',
        'Rácz', 'Simon', 'Takács', 'Ujvári', 'Varga', 'Wagner', 'Zámbó',
        'Zsembery', 'Zsigmond', 'Zsolnai',
    ];

    /** Keresztnevek — a vezetéknevekkel kombinálva adják a 300 egyedi nevet. */
    private const GIVEN_NAMES = [
        'Anna', 'Bence', 'Csilla', 'Dávid', 'Eszter', 'Ferenc', 'Gábor', 'Hanna',
        'István', 'Judit', 'Katalin', 'László', 'Márta', 'Norbert', 'Orsolya',
        'Péter', 'Réka', 'Sándor', 'Tamás', 'Zoltán',
    ];

    public function run(): void
    {
        // Defense-in-depth: a közvetlen `db:seed --class=...` hívás megkerülné a
        // DatabaseSeeder env-guardját, ezért itt is kell saját őr.
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException(
                'A BulkTestUsersSeeder csak local/testing környezetben futtatható.'
            );
        }

        $count       = max(1, (int) (env('COUNT') ?: self::DEFAULT_COUNT));
        $companyName = env('COMPANY') ?: self::DEFAULT_COMPANY;

        $company = Company::query()->where('name', $companyName)->first();

        if ($company === null) {
            $this->command?->error("BulkTestUsersSeeder: nincs ilyen cég: {$companyName}");

            return;
        }

        $created  = 0;
        $attached = 0;

        foreach ($this->names($count) as $index => $name) {
            // 1-alapú, fix szélességű sorszám — az e-mail így rendezhető és
            // egy pillantással beazonosítható marad.
            $email = sprintf('%s%03d@demo.local', self::EMAIL_PREFIX, $index + 1);

            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = User::create([
                    'name'               => $name,
                    'email'              => $email,
                    // Eldobható: ezek a fiókok nem bejelentkezésre valók.
                    'password'           => Hash::make(Str::random(32)),
                    'default_company_id' => $company->id,
                    'is_active'          => true,
                ]);
                $created++;
            }

            // syncWithoutDetaching: a user más cégbeli tagságát nem bántja, és
            // az újrafuttatás sem duplikálja a pivot-sort.
            if (! $user->companies()->whereKey($company->id)->exists()) {
                $user->companies()->syncWithoutDetaching([$company->id => ['is_default' => true]]);
                $attached++;
            }
        }

        $total = $company->users()->count();

        $this->command?->info(
            "BulkTestUsersSeeder: {$created} új user, {$attached} új cég-hozzárendelés "
            . "({$companyName}). A cég összes felhasználója: {$total}."
        );
    }

    /**
     * `$count` darab egyedi, ábécében egyenletesen szóródó név.
     *
     * A vezetéknév a gyors ciklus (minden lépésben lép egyet), a keresztnév a
     * lassú — így a nevek nem csoportosulnak az ábécé elejére, és a lista végi
     * „Zs" kezdetűekből is jut bőven.
     *
     * @return list<string>
     */
    private function names(int $count): array
    {
        $surnames = self::SURNAMES;
        $givens   = self::GIVEN_NAMES;

        $names = [];

        for ($i = 0; $i < $count; $i++) {
            $surname = $surnames[$i % count($surnames)];
            $given   = $givens[intdiv($i, count($surnames)) % count($givens)];

            $names[] = "{$surname} {$given}";
        }

        return $names;
    }
}
