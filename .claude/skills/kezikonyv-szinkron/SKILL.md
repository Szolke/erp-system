---
name: kezikonyv-szinkron
description: Visszamenőleges AUDIT — felméri, mely végfelhasználót érintő friss
  változás nincs átvezetve a felhasználói wikibe (docs/wiki/hu + docs/wiki/en) vagy
  a fejlesztői kézikönyvekbe (docs/deploy.md, docs/requirements.md, docs/backup.md),
  majd jóváhagyás-kapukkal végigvezet a javításon. Használd, amikor "frissítsd a
  kézikönyvet/wikit", "doksi-audit", "mi maradt le a kézikönyvből" vagy hasonló
  hangzik el. NEM ugyanaz, mint a `docs-sync`: az egy adott, friss commit-csoport
  UTÁNI napló-bejegyzést ír a progress.md/CHANGELOG.md-be; ez a skill tetszőleges
  időpontban, visszamenőleg is futtatható, a végfelhasználói kézikönyv egészét
  méri fel, és HU–EN paritást kényszerít ki.
---

# kezikonyv-szinkron — kézikönyv-audit és vezetett frissítés

## Hatókör

**Célok (ezekbe írhat, de csak a 3. fázisban, jóváhagyás után):**
- `docs/wiki/hu/*.md`, `docs/wiki/en/*.md`, `docs/wiki/README.md`
- `docs/deploy.md`, `docs/requirements.md`, `docs/backup.md`

**CSAK FORRÁS, SOHA nem írási cél** (ezeket a `docs-sync` tartja karban):
- `docs/progress.md`, `docs/CHANGELOG.md`, `docs/er-model.md`

Ha a felmérés közben kiderül, hogy ezek valamelyike elavult vagy hiányos, azt a
záró összefoglalóban JELEZD ("ez a docs-sync/kézi szerkesztés hatóköre"), de NE
írd át.

## ALAPSZABÁLY: három fázis, jóváhagyás-kapukkal

Ez a skill NEM tisztán csak-olvasás — a 3. fázisban fájlt ír. A human-in-the-loop
elvet szigorú fázis-kapuk biztosítják:

- **1. fázis (Felmérés):** csak olvasás. A végén KÖTELEZŐ megállás — hiánylista,
  szöveg-tervezés NÉLKÜL.
- **2. fázis (Terv):** csak olvasás + tervezés, MÉG NEM ír. A végén KÖTELEZŐ
  megállás — jóváhagyás vagy módosítás Szolkétól.
- **3. fázis (Javítás):** ír, de KIZÁRÓLAG jóváhagyott terv alapján, oldalanként,
  minden egyes oldalpárnál külön megállással ÍRÁS ELŐTT.

Egyik fázis sem ugorható át, és egyik fázisban sem szabad előreszaladni a
következőbe explicit jóváhagyás nélkül.

## KIMENETI BUDGET

- `head`, `head -c` vagy `tail` nélkül parancs nem futtatható (kivéve az 1.4
  lépésben megnevezett, RELEVÁNSNAK azonosított kézikönyv-oldalak teljes
  elolvasását — l. lentebb, ez a kimenetükre nézve ENYHÍTETT szabály, magára a
  parancs-kimenetre a ~40 sor / ~4000 karakter limit egyébként is érvényes).
- Minden git-parancs `git -C /home/szolke/projects/erp-system` formában, minden
  fájlművelet abszolút úton. Örökölt munkakönyvtárra egyik parancs se
  támaszkodjon.
- `/mnt/c/` vagy Windows-os elérési úton dolgozni TILOS.

## ÉRZÉKENY ADATOK

SOHA ne olvasd be a TARTALMÁT és ne írj bele: `backend/.env`, `.env`, bármilyen
`.env.*` (kivéve `.env.example`), `scripts/backup.conf`, bármi, ami NAV technikai
felhasználó adatot vagy SimplePay kulcsot tartalmazhat. A kézikönyvek egyébként
sem hivatkoznak konkrét titkos értékre — ha egy javasolt szövegben ilyesmi
felmerülne, hagyd ki és jelezd.

## Git-fegyelem

Semmilyen állapotváltoztató git-parancs (`add`, `commit`, `push`, `checkout`,
`switch`, `stash`, `reset`, `restore`, `merge`, `rebase`, `pull`) a skill futása
alatt — sem a felmérési, sem a javítási fázisban. A 3. fázis végén a commit-terv
felkínálása a feladat, maga a commit Szolke külön jóváhagyásával, a skillen
KÍVÜL történik.

---

## 0. Környezet-ellenőrzés (fail-fast)

```bash
R=/home/szolke/projects/erp-system
test -d "$R/.git" && echo "REPO OK" || echo "REPO HIÁNYZIK"
test -d "$R/docs/wiki/hu" && test -d "$R/docs/wiki/en" && echo "WIKI-FA OK" || echo "WIKI-FA HIÁNYZIK/ÁTALAKULT"
```

Ha a wiki-fa nem a várt szerkezetű (nincs `hu`/`en` alkönyvtár), NE találgass
alternatív útvonalat — jelezd, és kérdezd meg Szolkét, hova költözött.

---

# 1. FÁZIS — Felmérés (csak olvasás)

## 1.1 Wiki-fa és README-index futásidejű feltérképezése

Ne égess be korábbi futásból ismert fájllistát — mindig frissen deríts fel:

```bash
R=/home/szolke/projects/erp-system
echo "--- HU oldalak ---"; ls "$R/docs/wiki/hu" | sort
echo "--- EN oldalak ---"; ls "$R/docs/wiki/en" | sort
echo "--- README index (HU+EN hivatkozások) ---"
grep -n '](hu/\|](en/' "$R/docs/wiki/README.md"
```

A HU↔EN párosítás KIZÁRÓLAG a fájlnév NUMERIKUS PREFIXE (két számjegy, `01`–`08`
vagy amennyi éppen van) alapján történik — a szlug (a prefix utáni rész) NYELVEN-
KÉNT ELTÉRŐ FORDÍTÁS, nem egyezik és nem is suffix-szabály. Pl.
`hu/04-bizonylatok.md` párja `en/04-documents.md`, NEM egy azonos-nevű fájl.
A README két táblázata (Magyar / English) sorszám→relatív-út formában adja meg
ugyanezt a párosítást — ha a README és a fájlnév-prefixek ellentmondanak
egymásnak, azt KRITIKUS ellentmondásként jelezd, ne told el.

## 1.2 HU–EN paritás-ellenőrzés

```bash
R=/home/szolke/projects/erp-system
echo "--- HU sorszámok ---"; ls "$R/docs/wiki/hu" | grep -oE '^[0-9]+' | sort -u
echo "--- EN sorszámok ---"; ls "$R/docs/wiki/en" | grep -oE '^[0-9]+' | sort -u
echo "--- csak az egyik oldalon meglévő sorszámok (üres = teljes paritás) ---"
comm -3 <(ls "$R/docs/wiki/hu" | grep -oE '^[0-9]+' | sort -u) \
        <(ls "$R/docs/wiki/en" | grep -oE '^[0-9]+' | sort -u)
```

Ha a `comm -3` bármit kiad, az egy HU-only vagy EN-only oldalt jelent — ezt a
hiánylistában KÜLÖN, kiemelten kell szerepeltetni (ez PARITÁS-HIBA, nem csak
elmaradás). Ha egy funkció csak az egyik nyelven van dokumentálva egy egyébként
párban álló oldalon belül (pl. a HU oldal említ egy gombot, az EN párja nem), az
SZINTÉN paritás-hiba — ezt csak az 1.4/1.5 lépésben, a tartalmi olvasás után
lehet észrevenni.

## 1.3 Az utolsó érdemi kézikönyv-frissítés dátuma(i)

```bash
R=/home/szolke/projects/erp-system
for f in "$R"/docs/wiki/hu/*.md "$R"/docs/wiki/en/*.md "$R/docs/wiki/README.md" \
         "$R/docs/deploy.md" "$R/docs/requirements.md" "$R/docs/backup.md"; do
  D=$(git -C "$R" log -1 --format='%ad' --date=short -- "$f" 2>/dev/null)
  printf '%-45s %s\n' "${f#$R/}" "${D:-NINCS GIT-TÖRTÉNET}"
done
```

Jegyezd meg a LEGKORÁBBI (legrégebbi) dátumot ezek közül — ez lesz az 1.4 lépés
biztonságos alsó határa (onnantól nézzük át a kódot/naplót, hogy egyik oldal
elmaradását se hagyjuk ki). Az egyes fájlok EGYÉNI dátumát is tartsd meg — a
végső táblázatban oldalanként kell feltüntetni, mert eltérő ütemben avulnak.

**Fontos csapda:** egy friss dátum NEM jelenti automatikusan, hogy a releváns
tartalom is friss — lehet, hogy a legutóbbi módosítás egy MÁSIK, nem idevágó
funkcióhoz kötődött. Ellenőrizd a tényleges commit-üzenetet:

```bash
git -C /home/szolke/projects/erp-system log -1 --format='%h %s' -- <fájl>
```

Ha a commit-üzenet nem az adott oldal témájához kapcsolódik, a dátumot ne
tekintsd megbízható frissesség-jelzésnek — nézd meg a tényleges tartalmat (1.4).

## 1.4 Végfelhasználót érintő változások azonosítása (forrás: progress.md + CHANGELOG + git log)

Itt a "ne olvass be teljes fájlt" szabály a `progress.md`/`CHANGELOG.md`
LEGUTÓBBI szakaszára ENYHÍTETT (ezek a fájlok igazságforrások, de csak a
legfrissebb, még nem auditált részük releváns):

```bash
R=/home/szolke/projects/erp-system
wc -l "$R/docs/progress.md"
head -40 "$R/docs/progress.md" | head -c 3000
echo; echo "--- CHANGELOG teteje (legutóbbi bejegyzések) ---"
head -60 "$R/docs/CHANGELOG.md" | head -c 3000
echo; echo "--- git log az 1.3 legkorábbi dátuma óta (kód, nem doksi) ---"
git -C "$R" log --oneline --since="<1.3-ból>" -- backend/ frontend/ | head -40
```

Ebből (és szükség esetén `git show <hash> --stat`-tal egy-egy bizonytalan
commitra) állapítsd meg, mely változások érintik a VÉGFELHASZNÁLÓT — új gomb, új
képernyő/oldal, megváltozott munkafolyamat, új beállítási lehetőség. A TISZTÁN
belső/technikai változásokat (refaktor, teszt, migráció-belső mező, backend-only
API-alakítás UI-hatás nélkül) HAGYD KI — ezek nem tartoznak a felhasználói
kézikönyvbe.

Minden azonosított user-facing változáshoz keress RELEVÁNS kézikönyv-oldal-
jelölteket kulcsszó-egyezéssel:

```bash
grep -rniE '<kulcsszó1>|<kulcsszó2>' /home/szolke/projects/erp-system/docs/wiki 2>/dev/null | head -20
```

A találatokból (vagy azok hiányából) állítsd össze a RELEVÁNS oldalak szűk
listáját — CSAK ezeket olvasd el teljes egészében (Read tool), ne az egész
wikit és ne az egész repót. A wiki-oldalak rövidek (2–12 KB), ez belefér.

## 1.5 Kereszthivatkozás — hiánylista összeállítása

A 1.4-ben elolvasott releváns oldalak tartalma és az azonosított user-facing
változások alapján állapítsd meg oldalanként (HU+EN külön-külön, mert a
tartalom nyelvenként eltérhet még akkor is, ha egyébként párban állnak):

- a változás EGYÁLTALÁN nincs említve,
- RÉSZBEN van említve, de ELLENTMOND a jelenlegi viselkedésnek (pl. fix
  oszloplistát ír le egy azóta konfigurálhatóvá vált táblázatnál),
- csak az EGYIK nyelven szerepel (paritás-hiba a tartalom szintjén, nem csak
  fájl-szinten).

## 1.6 Kimenet — MEGÁLLÁS

Az összefoglaló ELSŐ sora kötelezően egy státusz, pontosan az alábbi három érték
egyikével (ha több is igaz, a sorrend: PARITÁS-HIBA > ELMARADÁS > SZINKRONBAN):

- `KÉZIKÖNYV: SZINKRONBAN` — nincs azonosított hiány, fájl-szintű és tartalmi
  HU–EN paritás is rendben.
- `KÉZIKÖNYV: ELMARADÁS` — van legalább egy end-user-facing változás, ami nincs
  átvezetve, de a HU–EN fájl-szintű paritás rendben van.
- `KÉZIKÖNYV: PARITÁS-HIBA` — van HU-only/EN-only oldal, vagy egy oldalpáron
  belül a két nyelv tartalma érdemben eltér (az egyik dokumentál valamit, amit
  a másik nem).

A státusz-sor után:
- **Hiánylista** — oldalanként: fájl, mi hiányzik/elavult, melyik forrás-
  commit(ok) miatt.
- **Paritás-állapot** — fájl-szintű (1.2) és tartalmi (1.5) együtt.
- **Nem-kézikönyv doksik állapota** — ha a progress.md/CHANGELOG/er-model.md
  vizsgálat közben elavultnak tűnt, egy mondatban jelezd, de NE javasolj rá
  szöveget (az a `docs-sync`/kézi szerkesztés hatásköre).

**Itt ÁLLJ MEG.** Szöveget, javasolt megfogalmazást MÉG NE tervezz — az a 2.
fázis.

---

# 2. FÁZIS — Terv + jóváhagyás (nem ír, csak olvasás alapján tervez)

Csak akkor fut, ha az 1. fázis kimenete `ELMARADÁS` vagy `PARITÁS-HIBA` volt, és
Szolke jóváhagyta a folytatást.

- A hiánylistából állíts össze egy oldalankénti tennivaló-tervet. A wikinél a
  terv MINDIG HU+EN PÁRBAN szerepeljen — még akkor is, ha csak az egyik nyelven
  találtál hiányt, mert a javítás a párját is érintheti (konzisztencia).
- **Keresztmetszeti funkciónál** (olyan változás, ami sok listát/oldalt érint
  egyformán — pl. egy közös komponens új viselkedése minden listanézeten) NE
  dönts magadtól: tárd Szolke elé a két lehetőséget — (a) egy központi helyen,
  általánosan leírva, a többi érintett oldal csak utal rá, vagy (b) oldalanként
  megismételve — és kérj döntést.
- Jelezd, ha a `docs/wiki/README.md` indexét is érinteni kell (pl. új oldal,
  átnevezés) vagy egy meglévő oldalon belül új szakaszt kell nyitni.
- Az egyes tervezett tételekhez röviden indokold, MELYIK 1. fázisbeli hiányt
  fedi le.

**Itt ÁLLJ MEG.** Várd Szolke jóváhagyását vagy módosítását, mielőtt bármit
írnál. Ha Szolke módosítja a tervet, a módosított terv szerint folytasd — ne a
saját eredeti javaslatod szerint.

---

# 3. FÁZIS — Vezetett javítás (ír, oldalanként, jóváhagyás után)

Csak a jóváhagyott (esetleg Szolke által módosított) terv alapján, oldalanként
haladva:

- Minden oldalPÁRhoz (HU+EN együtt, egy körben) mutasd meg a konkrét javasolt
  szöveget vagy diffet MINDKÉT nyelvre egyszerre, ÁLLJ MEG, és csak explicit
  jóváhagyás után írd meg (Edit/Write) a fájlokat.
- **Az egyik nyelvet SOHA ne frissítsd a párja nélkül egyazon körben** — ha
  Szolke csak az egyiket hagyja jóvá, jelezd, hogy a másik emiatt átmenetileg
  paritás-hiányos marad, és ezt a következő futtatásig nyitva kell tartani.
- A stílust és terminológiát a `CLAUDE.md`-ből és a MEGLÉVŐ, változatlan wiki-
  oldalak hangvételéből vedd át (tömör, felhasználó-szemléletű, magyar és
  angol wiki-oldal hangneme külön-külön saját magára konzisztens legyen — ne
  keverd a két nyelv stílusát).
- Ha a terv README-index-módosítást is tartalmazott, azt is jóváhagyás után,
  külön megmutatva írd meg.

Minden egyes oldalpár megírása után rövid visszaigazolás: mely fájlok kerültek
frissítésre.

## A 3. fázis végén

NE commitolj, NE pushol j. Add meg a commit-tervet: érintett fájlok listája +
egy rövid, tárgyilagos ANGOL commit-üzenet javaslat (esetleg több, ha a
javítások tematikusan szétválnak). A tényleges commit Szolke külön
jóváhagyásával, a skillen kívül történik.

---

## Hibakezelés

Ha bármelyik parancs hibával tér vissza, vagy egy fájl olvasása/írása
akadályba ütközik, NE hagyd ki csendben — jelezd az összefoglalóban, melyik
lépés nem futott le és milyen hibával. Hiányos adatból ne vonj le
következtetést (pl. ne állíts `SZINKRONBAN` státuszt, ha egy fájl dátumát vagy
tartalmát nem sikerült ellenőrizni — ilyenkor jelezd bizonytalanként).
