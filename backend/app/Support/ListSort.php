<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Listanézetek WHITELISTELT rendezése — a kérésből érkező rendezési kérés
 * feloldása egy, a KÓDBAN rögzített oszlop-leképezésre.
 *
 * Miért kell külön osztály: a rendezés az egyetlen olyan lista-paraméter, ami
 * közvetlenül egy SQL-azonosítóra (oszlopnévre) fordul — egy nyers
 * `orderBy($request->get('sort_by'))` SQL-injektálható lenne. Az itt kiadott
 * `column` ezért SOHA nem a kérésből származik, hanem a hívó által megadott
 * `$whitelist` ÉRTÉK-oldaláról, ami fordítási időben ismert konstans; a kérés
 * csak a whitelist KULCSÁT (a frontend oszlopkulcsát) választhatja ki, és
 * ismeretlen kulcs esetén némán az alapértelmezésre esünk vissza — nem hibázunk,
 * mert egy elavult mentett preferencia vagy egy kézzel átírt URL nem tehet
 * használhatatlanná egy listát.
 *
 * A frontend oszlopkulcs → DB-oszlop indirekció szándékos: a nyilvános API-felület
 * (`sort_by=gross`) így akkor is változatlan marad, ha a mögöttes oszlop neve
 * (`gross_total`) egyszer megváltozik.
 *
 * Pilot: jelenleg a DocumentController (Bizonylatok) használja. A leképezést
 * szándékosan a hívó controller adja (`SORTABLE_COLUMNS` konstans), hogy a
 * későbbi, több listára kiterjesztett körben ez az osztály változatlanul,
 * közös trait/helper mögé emelve is használható legyen.
 */
final class ListSort
{
    private function __construct(
        /** A frontend oszlopkulcsa (a whitelist kulcsa) — pl. 'gross'. */
        public readonly string $key,
        /** A whitelistből feloldott, KÓDBAN rögzített oszlopnév — pl. 'gross_total'. */
        public readonly string $column,
        /** 'asc' vagy 'desc' — más érték ide nem juthat. */
        public readonly string $direction,
        /** Igaz, ha a kérés nem (vagy érvénytelenül) kért rendezést, tehát az alapértelmezés érvényesül. */
        public readonly bool $isDefault,
    ) {
    }

    /**
     * @param  array<string, string>  $whitelist  frontend oszlopkulcs => lekérdezés-oszlopnév
     * @param  string  $defaultKey  a `$whitelist` egy KULCSA — a visszaesési rendezés
     * @param  'asc'|'desc'  $defaultDirection
     */
    public static function fromRequest(
        Request $request,
        array $whitelist,
        string $defaultKey,
        string $defaultDirection = 'desc',
        string $byParam = 'sort_by',
        string $dirParam = 'sort_dir',
    ): self {
        $requestedKey = $request->string($byParam)->trim()->value();
        $requestedDir = strtolower($request->string($dirParam)->trim()->value());

        // Ismeretlen kulcs → alapértelmezés. Szándékosan némán: egy régi mentett
        // preferencia (azóta megszűnt oszlop) vagy egy kézzel írt URL sem
        // hibázhat, csak elveszti a rendezését.
        $isDefault = ! array_key_exists($requestedKey, $whitelist);
        $key = $isDefault ? $defaultKey : $requestedKey;

        // Ismeretlen oszlop esetén az IRÁNY is visszaesik: a végpontnak ilyenkor
        // a TELJES eddigi alapértelmezett rendezését kell adnia. Egy fél-elfogadott
        // `?sort_by=nincs_ilyen&sort_dir=asc` különben csendben megfordítaná a
        // listát, ami a "nem tört meg a régi viselkedés" elvárást sértené.
        //
        // Ha viszont az OSZLOP érvényes és csak az irány nem
        // (`?sort_by=partner&sort_dir=xxx`), a kért oszlop megmarad — ott a
        // felhasználó szándéka egyértelmű, csak a finomhangolás hibás.
        $direction = (! $isDefault && in_array($requestedDir, ['asc', 'desc'], true))
            ? $requestedDir
            : $defaultDirection;

        return new self($key, $whitelist[$key], $direction, $isDefault);
    }

    /**
     * ORDER BY-töredék: az elsődleges rendezés, majd a hívó által megadott,
     * stabilizáló másodlagos szempontok.
     *
     * `NULLS LAST` mindkét irányban: a PostgreSQL alapból ASC-nél utolsóként,
     * DESC-nél ELSŐKÉNT hozza a NULL-okat — a bizonylatlistán viszont több
     * oszlop (nyugta `due_date`/`payment_status`, hiányzó árfolyamú sor
     * `gross_total_huf`-ja) szándékosan NULL, és ezeket az üres cellákat a
     * felhasználó egyik irányban sem a lista tetején várja.
     *
     * @param  string[]  $tieBreakers  KÓDBAN rögzített, nyers ORDER BY-töredékek
     *                                 (pl. `['issue_date DESC', 'id DESC']`) — ide
     *                                 kéréssel befolyásolt érték SOHA nem kerülhet.
     */
    public function toOrderBySql(array $tieBreakers = []): string
    {
        $terms = [sprintf('%s %s NULLS LAST', $this->column, strtoupper($this->direction))];

        foreach ($tieBreakers as $fragment) {
            // Ha az elsődleges oszlop egyben tie-breaker is, a második említés
            // felesleges (a SQL figyelmen kívül hagyná) — ne kerüljön bele.
            if (str_starts_with($fragment, $this->column.' ')) {
                continue;
            }
            $terms[] = $fragment;
        }

        return implode(', ', $terms);
    }
}
