---
name: hol-tartunk
description: Felveszi a fonalat egy új sessionben — elolvassa a CLAUDE.md-t és a
  progress.md-t célzott szakasz-kivágással (nem teljes beolvasással), lekéri a git
  állapotot (branch, remote-eltérés, stash, uncommitted munka, nem pusholt commitok),
  ellenőrzi a dokumentáció-szinkront (utolsó 5 commitból hány szerepel a
  progress.md/CHANGELOG.md-ben), feltételesen a migrációk állapotát és a
  lockfile-drift-et, majd korlátozott
  kimenetű tényösszefoglalót ad: hol tart a projekt, van-e félbehagyott munka, van-e
  ellentmondás a dokumentáció és a kód között, mi a következő teendő.
---

# hol-tartunk — session-felvevő

## ALAPSZABÁLY: ez egy CSAK-OLVASÁS skill

Ez a skill kizárólag tényeket gyűjt. TILOS:
- bármilyen fájlt létrehozni, módosítani vagy törölni
- `git add`, `commit`, `push`, `checkout`, `switch`, `stash`, `reset`, `restore`,
  `merge`, `rebase`, `pull` — SEMMILYEN állapotváltoztató git-parancs
- migrációt futtatni, konténert indítani/leállítani/törölni, `.env`-et olvasni
- döntést hozni, javítást javasolni, vagy elkezdeni a következő feladatot

Ha bármilyen problémát látsz, azt LEÍROD és MEGÁLLSZ. A döntés Szolkéé.

## KIMENETI BUDGET

- Minden parancs kimenete korlátozott: `head`, `head -c` vagy `tail` nélkül parancs
  nem futtatható.
- Egyetlen parancs kimenete sem haladhatja meg a ~40 sort / ~4000 karaktert.
- Ha egy kimenet levágásra került, azt az összefoglalóban jelezni kell.
- Fájlt teljes egészében beolvasni TILOS a CLAUDE.md kivételével.

Egyetlen parancs sem támaszkodhat örökölt munkakönyvtárra. Minden git-parancs
`git -C /home/szolke/projects/erp-system` formában fut, minden fájlművelet
abszolút úton.

## ÉRZÉKENY ADATOK

SOHA ne írd ki terminálba, logba vagy az összefoglalóba ezeknek a TARTALMÁT:
- `backend/.env`, `.env`, bármilyen `.env.*` (kivéve `.env.example`)
- `scripts/backup.conf`
- bármi, ami NAV technikai felhasználó adatot vagy SimplePay kulcsot tartalmazhat

Ezekre KIZÁRÓLAG a fájlnév és a „módosítva / új" tény említhető. `git diff`-et
ezekre a fájlokra futtatni TILOS.

---

## 0. Környezet-ellenőrzés (fail-fast)

```bash
test -d /home/szolke/projects/erp-system/.git && echo "REPO OK" || echo "REPO HIÁNYZIK"
```

Ha nem OK: ÁLLJ MEG, jelezd, és ne futtass több parancsot. Ha az aktuális munkakönyvtár
`/mnt/c/` alatt van, azt is jelezd hibaként.

## 1. CLAUDE.md — munkamódszer és architektúra

Olvasd el: `/home/szolke/projects/erp-system/CLAUDE.md`

Ez a KIMENETI BUDGET „ne olvass be teljes fájlt" szabályának egyetlen kivétele.
A CLAUDE.md adja a kommunikációs stílust, a git-konvenciókat és az architekturális
elveket, amelyek MINDEN munkamenetre érvényesek. Ne foglald össze a kimenetben —
csak kövesd.

## 2. docs/progress.md — célzott szakasz-kivágás

Ez a fájl néhány bekezdése több ezer karakteres, ezért SOSEM olvasd be sortartományban
(még kis tartományban sem) — mindig célzott kivágással dolgozz:

```bash
P=/home/szolke/projects/erp-system/docs/progress.md
wc -l -c "$P"
head -40 "$P" | head -c 3000
echo; echo "--- SORSZÁMOK ---"
grep -n 'Utolsó frissítés' "$P" | cut -d: -f1 | head -3
grep -n 'Legutóbbi állapot' "$P" | head -3 | awk '{print substr($0,1,400)}'
echo; echo "--- MÉG HÁTRAVAN ---"
awk '/^## Még hátravan/{f=1} f&&/^## /&&!/Még hátravan/{exit} f' "$P" | head -c 4000
```

Az „Utolsó frissítés" grep KIZÁRÓLAG a sor lokalizálására szolgál (`cut -d: -f1`
csak a sorszámot adja vissza) — a DÁTUMOT a `head -40` kimenetéből olvasd ki,
mert az a sor a fájl elején van. A „Legutóbbi állapot" sor viszont a
`head -40`-en TÚL, a fájl közepén van, ezért a tartalmát az `awk
substr($0,1,400)` kivágásból kell venni — ez karakter-, nem bájt-alapú vágás
(UTF-8-biztos), szemben a korábbi `cut -c`-vel, ami többbájtos karaktert
csonkolt el (hibás kimenetet adott). A vágás mindkét helyen szándékos, nem
kell külön jelezni levágásként.

Ha az `awk` üres kimenetet ad (a fejléc neve megváltozott), NE találgass
tartományokkal — `grep -n '^#' /home/szolke/projects/erp-system/docs/progress.md | head -40`
alapján keresd meg a tényleges fejlécet, és azt vágd ki ugyanezzel az
awk-mintával (a fejléc-nevet cserélve rá). Ha így sem megy, jelezd az
összefoglalóban, hogy a „Még hátravan" szakasz nem volt kinyerhető.

Kiemelten keresd (a fenti parancsok kimenetéből):
- Az **"Utolsó frissítés"** DÁTUMÁT (a `head -40` blokkból) — a 3. lépésben ezt
  kell összevetni a git loggal.
- A **"Még hátravan"** szakasz (awk-kivágás) ELSŐ alszakaszát — ha van „Következő
  lépés" / „ÚJ MUNKAMENET EZZEL KEZDJEN" jelölés, az a legsürgetőbb.
- A **"Legutóbbi állapot"** sor tartalmát (az `awk substr` kimenetből) — ha ez
  nem elég, célzott `sed -n 'X,Yp' /home/szolke/projects/erp-system/docs/progress.md`-vel
  kiegészítve, de a KIMENETI BUDGET-en belül maradva.

## 3. Git — állapot, log, uncommitted, nem pusholt commitok

Két blokk, összevonva a korábbi külön branch/stash/log/diff lépéseket (kevesebb
tool-hívás):

```bash
R=/home/szolke/projects/erp-system
echo "--- BRANCH ---";  git -C "$R" rev-parse --abbrev-ref HEAD
echo "--- STATUS ---";  git -C "$R" status -sb | head -30
echo "--- STASH ---";   git -C "$R" stash list | head -10
echo "--- LOG ---";     git -C "$R" log -12 --date=short --pretty=format:'%h %ad %s'
echo; echo "--- DIFFSTAT ---"; git -C "$R" diff --stat | tail -20
echo "--- STAGED ---";  git -C "$R" diff --cached --stat | tail -20
```

Második blokk — CSAK akkor fut, ha a STATUS sor `[ahead N]`-t mutat:

```bash
git -C /home/szolke/projects/erp-system log @{u}..HEAD --oneline | head -20
```

**KRITIKUS ellenőrzések:**
- Ha a branch neve `HEAD` → **detached HEAD**, ezt hangsúlyosan jelezd.
- Ha a STATUS sor `[ahead N]`-t mutat → van nem pusholt commit; futtasd a fenti
  második blokkot, és a konkrét commitokat (nem csak a számot) is jelezd.
- Ha `[behind N]` → a remote előrébb tart, mint a lokális.
- Ha a `git stash list` NEM üres → egy korábbi session félretett munkája. Ezt
  MINDIG emeld ki, akkor is, ha a git status egyébként tiszta. Ne alkalmazd,
  ne dobd el — csak jelezd.
- **Dátum-összevetés:** hasonlítsd össze a LOG legfelső sorának dátumát a
  progress.md „Utolsó frissítés" dátumával (2. lépés). Ha a commit újabb → a
  dokumentáció ELAVULT lehet, van dokumentálatlan munka. Ha a progress.md dátuma
  újabb → vagy uncommitted munka van, vagy a progress.md előre írt le még el nem
  végzett dolgot. Jelezd mindkét esetet.
- A `--stat` csak fájlnevet és sorszámot ad, tartalmat NEM — ez szándékos.
  `.env*` / `scripts/backup.conf` fájlokra ezen felül semmilyen diffet ne futtass.
- **KRITIKUS:** Ha a DIFFSTAT vagy STAGED BÁRMILYEN módosított vagy untracked
  fájlt mutat (különösen `frontend/` vagy `backend/` alatt), azt EXPLICIT ki kell
  emelni — fájlnév + nagyságrend (hány sor változott). Ez egy korábbi session
  félbehagyott munkája lehet; figyelmen kívül hagyni tilos.
- Ha a progress.md „kész" vagy „commitolva" állapotot ír, de a git status
  uncommitted kódot mutat: jelezd az ELLENTMONDÁST — ne simítsd el, ne
  magyarázd meg, ne találgasd ki az okát.

Új migrációs fájl az uncommitted listában → l. 6/c (Feltételes mélyellenőrzések).

## 4. Dokumentáció-lemaradás ellenőrzése (commit-lefedettség)

Az utolsó commit hash-ét ÖNMAGÁBAN kereső check hamis pozitívot ad, ha az
utolsó commit egy tisztán dokumentációs (pl. CHANGELOG-frissítő) commit, amit a
progress.md szabályosan nem említ. **Strukturális hamis pozitív** ugyanígy adódik
a „Record ... in progress log" / „Document ... in changelog" típusú, MAGUK A
DOKUMENTÁLÓ commitokból is — ezek definíció szerint sosem hivatkoznak önmagukra.
Ezért a ciklus előbb kiszűri ezeket, és csak az érdemi (kód-/funkció-) commitokat
vizsgálja — nagyobb, `-8`-as ablakból, mert a szűrés csökkenti az érdemi
commitok számát:

```bash
R=/home/szolke/projects/erp-system
FOUND=0; CHECKED=0; MISSING=""
while IFS='|' read -r H MSG; do
  case "$MSG" in
    [Dd]ocument*|[Rr]ecord*|docs:*|*changelog*|*progress\ log*) continue ;;
  esac
  CHECKED=$((CHECKED+1))
  if grep -q "$H" "$R/docs/progress.md" "$R/docs/CHANGELOG.md" 2>/dev/null; then
    FOUND=$((FOUND+1))
  else
    MISSING="$MISSING $H"
  fi
done < <(git -C "$R" log -8 --pretty='%h|%s')
echo "vizsgált érdemi commit: $CHECKED, ebből dokumentálva: $FOUND"
echo "nem dokumentált:${MISSING:- nincs}"
```

Értékelés:
- Ha `CHECKED = 0` → „nincs érdemi commit a vizsgált ablakban" — NEM
  figyelmeztetés (csak dokumentáló commitok voltak az utolsó 8-ban).
- Ha `FOUND = CHECKED` → szinkronban.
- Ha `FOUND < CHECKED` → figyelmeztetés, a hiányzó hash-ekkel (`MISSING`).

## 5. Környezet állapota — csak olvasás

```bash
cd /home/szolke/projects/erp-system && docker compose ps --format '{{.Service}} {{.State}}' 2>/dev/null | head -20 \
  || echo "Docker Compose nem elérhető vagy nem fut"
```

A kimenetből a `laravel.test` sor meglétét külön jegyezd meg — a 6/a lépés
(migrációk állapota) ettől függ. Csak jelezd, mely szolgáltatások futnak
(`laravel.test`, `pgsql`, `redis`, `frontend`). Konténert indítani, leállítani
vagy újraépíteni TILOS.

## 6. Feltételes mélyellenőrzések

Ezek NEM futnak mindig — csak a megadott feltétel teljesülésekor, hogy egy sima
induláskor ne fusson felesleges parancs.

**(a) Migrációk állapota** — CSAK ha az 5. lépés szerint fut a `laravel.test`
konténer:

```bash
cd /home/szolke/projects/erp-system || exit 1
OUT=$(docker compose exec -T laravel.test php artisan migrate:status 2>&1)
if [ $? -ne 0 ]; then
  echo "MIGRÁCIÓK: a migrate:status nem futott le (l. hibakezelés)"
else
  HITS=$(printf '%s\n' "$OUT" | grep -i -E 'pending|error' | head -20)
  if [ -z "$HITS" ]; then
    echo "MIGRÁCIÓK: minden lefutott"
  else
    echo "MIGRÁCIÓK: PENDING/ERROR:"; printf '%s\n' "$HITS"
  fi
fi
```

A kimenetet egy `OUT` shell-változóba fogd fel, NE fájlba írd — a skill
csak-olvasás jellege a `/tmp`-re is vonatkozik. **Fontos:** a `HITS` ürességét
vizsgáljuk (`[ -z "$HITS" ]`), NEM a `grep | head` pipeline exit kódját — egy
pipeline exit kódja mindig az UTOLSÓ tag (itt: `head`) kódja, ami akkor is 0,
ha a `grep` nem talált semmit, ezért egy `grep ... | head ... || echo ...`
minta némán, üres kimenettel térne vissza „nincs pending" esetén ahelyett, hogy
kiírná a „minden lefutott" üzenetet. Ha nincs pending/error találat: „minden
lefutott". Ha van pending: hangsúlyos figyelmeztetés — a progress.md dokumentál
korábbi esetet, ahol egy commitolt, de le nem futtatott migráció 500-as hibát
okozott. Migrációt futtatni TILOS.

**(b) Függőség-drift** — CSAK ha az utolsó 5 commit VAGY az uncommitted fájlok
között szerepel `composer.lock` vagy `package-lock.json`:

```bash
git -C /home/szolke/projects/erp-system log -5 --name-only --pretty=format: | grep -E 'composer\.lock|package-lock\.json' | sort -u
```

Ha van találat, az összefoglalóban jelezd: elavult lehet a `vendor/` vagy a
`node_modules/`. Telepítést NE futtass.

**(c) Új migrációs fájl az uncommitted listában** — ha a 3. lépés DIFFSTAT/STAGED
kimenetében `database/migrations/` alatti ÚJ fájl szerepel, azt itt emeld ki —
nem tudható, hogy le lett-e futtatva.

## Szándékosan kimaradó ellenőrzések

- teszt-futtatás (lassú, és commit-előfeltételként úgyis megvan)
- `.env` és `.env.example` kulcs-drift összevetés (szembemenne a skill saját
  .env-tiltásával)
- CHANGELOG.md ↔ progress.md hash-egyeztetés — NEM cél; a CHANGELOG.md a
  4. lépésben kizárólag a dokumentáció-lemaradás check hamis pozitívjának
  kiszűrésére szolgál (pl. tisztán CHANGELOG-frissítő commit, amit a
  progress.md szabályosan nem említ), nem önálló hash-egyeztetésre.

## 7. Kiegészítő dokumentumok — csak létezés-ellenőrzés

```bash
ls -1 /home/szolke/projects/erp-system/docs/deploy.md \
      /home/szolke/projects/erp-system/docs/requirements.md \
      /home/szolke/projects/erp-system/docs/backup.md \
      /home/szolke/projects/erp-system/docs/er-model.md 2>&1
```

Ne olvasd el őket — csak tudd, hogy ott vannak, és jelezd, melyik hiányzik.

## 8. Hibakezelés

Ha bármelyik parancs hibával tér vissza, azt NE hagyd ki csendben. Az összefoglaló
végén sorold fel: melyik lépés nem futott le és milyen hibával. Hiányos adatból
NE vonj le következtetést. Ha egy parancs kimenete a KIMENETI BUDGET miatt
levágásra került, azt is jelezd.

---

## Kimenet — ezt tedd elém

Az összefoglaló ELSŐ sora kötelezően egy státusz-fejléc, pontosan az alábbi
három érték egyikével:

- `ÁLLAPOT: TISZTA` — nincs uncommitted munka, nincs stash, a dok szinkronban van
- `ÁLLAPOT: FÉLBEHAGYOTT MUNKA` — uncommitted változás vagy nem üres stash van
- `ÁLLAPOT: ELLENTMONDÁS` — a progress.md és a git/kód állapota ütközik

Ha több is igaz egyszerre, a sorrend: ELLENTMONDÁS > FÉLBEHAGYOTT MUNKA > TISZTA.

A státusz-fejléc után rövid (kb. 20–30 soros) összefoglaló, MAGYARUL, ebben a
struktúrában. Ha egy szakaszhoz nincs adat, írd oda, hogy „nincs" — ne hagyd ki.

**⚠️ Figyelmeztetések** *(ha van ilyen, ez legyen ELÖL — különben hagyd ki a szakaszt)*
- detached HEAD / nem pusholt commitok / behind állapot
- nem üres stash
- progress.md és git log dátum-eltérése
- ellentmondás a progress.md és a git status között
- nem futtatott parancs / hiba / levágott kimenet

**Projekt állapota** — a progress.md átadási pontja szerint, egy bekezdés.

**Git állapot** — branch, ahead/behind, stash (van/nincs, hány db).

**Utolsó commitok** — a releváns 4–6 sor, dátummal.

**Uncommitted munka** — igen/nem. Ha igen: fájlonként név + hány sor változott,
staged és unstaged bontásban. Migrációs fájlt külön kiemelve.

**Környezet** — mely Docker-szolgáltatások futnak.

**Migrációk** — a 6/a eredménye, három lehetséges állapot: „minden lefutott",
„PENDING: <lista>" (ez utóbbi hangsúlyos figyelmeztetés is), vagy „nem
ellenőrizve / sikertelen" (konténer nem fut, vagy a parancs elszállt).

**Dokumentáció-szinkron** — a 4. lépés hash-check eredménye.

**Következő teendő** — a progress.md szerint. Ne okoskodj hozzá, ne bővítsd ki:
idézd vagy pontosan summázd, amit a fájl mond. Ha a fájl nem mond egyértelműt,
azt írd: „a progress.md nem jelöl ki egyértelmű következő lépést".

**Kiegészítő docs** — melyik létezik a 4-ből.

Az összefoglaló után ÁLLJ MEG. Ne kérdezz rá, hogy folytathatod-e — csak várd meg,
mit mond Szolke.
