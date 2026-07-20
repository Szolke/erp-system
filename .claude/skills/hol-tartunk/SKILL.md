---
name: hol-tartunk
description: Felveszi a fonalat egy új sessionben — elolvassa a CLAUDE.md-t és a
  progress.md-t, lekéri a git állapotot (branch, remote-eltérés, stash, uncommitted
  munka), majd rövid tényösszefoglalót ad: hol tart a projekt, van-e félbehagyott
  munka, van-e ellentmondás a dokumentáció és a kód között, mi a következő teendő.
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

Ez adja a kommunikációs stílust, a git-konvenciókat és az architekturális elveket,
amelyek MINDEN munkamenetre érvényesek. Ne foglald össze a kimenetben — csak kövesd.

## 2. docs/progress.md — aktuális állapot

Először nézd meg a méretét és a szerkezetét:

```bash
wc -l /home/szolke/projects/erp-system/docs/progress.md
grep -n '^#' /home/szolke/projects/erp-system/docs/progress.md | tail -40
```

- Ha **800 sornál rövidebb**: olvasd el egészben.
- Ha **hosszabb**: olvasd el az első 60 sort (fejléc + „Utolsó frissítés"), majd a
  fenti `grep` kimenete alapján CÉLZOTTAN a releváns szakaszokat (`Még hátravan`,
  `Következő lépés`, `Legutóbbi állapot`) — soronkénti tartományokkal, ne a fájl
  közepének kivágásával.

Kiemelten keresd:
- Az **"Utolsó frissítés"** sort (fejléc alatt) — ennek a DÁTUMÁT jegyezd meg, a
  4. pontban össze kell vetni a git loggal.
- A **"Még hátravan"** szakasz ELSŐ alszakaszát (ha van „Következő lépés" /
  „ÚJ MUNKAMENET EZZEL KEZDJEN" jelölés, az a legsürgetőbb).
- A **"Legutóbbi állapot"** összefoglaló sort (a „Kész modulok" szakasz végén).

## 3. Git — branch, remote-eltérés, stash

```bash
cd /home/szolke/projects/erp-system
git rev-parse --abbrev-ref HEAD
git status -sb | head -1
git stash list
```

**KRITIKUS ellenőrzések:**
- Ha a branch neve `HEAD` → **detached HEAD**, ezt hangsúlyosan jelezd.
- Ha a `status -sb` első sora `[ahead N]` → van nem pusholt commit.
- Ha `[behind N]` → a remote előrébb tart, mint a lokális.
- Ha a `git stash list` NEM üres → egy korábbi session félretett munkája. Ezt
  MINDIG emeld ki, akkor is, ha a git status egyébként tiszta. Ne alkalmazd,
  ne dobd el — csak jelezd.

## 4. git log — legutóbbi commitok, dátummal

```bash
git -C /home/szolke/projects/erp-system log -15 --date=short --pretty=format:'%h %ad %s'
```

**Dátum-összevetés:** hasonlítsd össze a legfelső commit dátumát a progress.md
„Utolsó frissítés" dátumával.
- Ha a **commit újabb**, mint a progress.md → a dokumentáció ELAVULT lehet, van
  dokumentálatlan munka. Jelezd.
- Ha a **progress.md újabb**, mint az utolsó commit → vagy uncommitted munka van,
  vagy a progress.md előre írt le még el nem végzett dolgot. Jelezd.

## 5. git status — uncommitted változások

```bash
git -C /home/szolke/projects/erp-system status -sb
git -C /home/szolke/projects/erp-system diff --stat
git -C /home/szolke/projects/erp-system diff --cached --stat
```

A `--stat` csak fájlnevet és sorszámot ad, tartalmat NEM — ez szándékos.
A `.env` / `backup.conf` fájlokra ezen felül semmilyen diffet ne futtass.

**KRITIKUS:** Ha a git status BÁRMILYEN módosított vagy untracked fájlt mutat
(különösen `frontend/` vagy `backend/` alatt), azt EXPLICIT ki kell emelni —
fájlnév + nagyságrend (hány sor változott). Ez egy korábbi session félbehagyott
munkája lehet; figyelmen kívül hagyni tilos.

Ha a progress.md „kész" vagy „commitolva" állapotot ír, de a git status
uncommitted kódot mutat: jelezd az ELLENTMONDÁST — ne simítsd el, ne
magyarázd meg, ne találgasd ki az okát.

**Migrációs fájlok:** ha az uncommitted fájlok között `database/migrations/` alatti
ÚJ fájl van, azt külön emeld ki — nem tudható, hogy le lett-e futtatva.

## 6. Környezet állapota — csak olvasás

```bash
docker compose -f /home/szolke/projects/erp-system/docker-compose.yml ps 2>/dev/null \
  || echo "Docker Compose nem elérhető vagy nem fut"
```

Csak jelezd, mely szolgáltatások futnak (`laravel.test`, `pgsql`, `redis`, `frontend`).
Konténert indítani, leállítani vagy újraépíteni TILOS.

## 7. Kiegészítő dokumentumok — csak létezés-ellenőrzés

```bash
cd /home/szolke/projects/erp-system/docs && ls -1 deploy.md requirements.md backup.md er-model.md 2>&1
```

Ne olvasd el őket — csak tudd, hogy ott vannak, és jelezd, melyik hiányzik.

## 8. Hibakezelés

Ha bármelyik parancs hibával tér vissza, azt NE hagyd ki csendben. Az összefoglaló
végén sorold fel: melyik lépés nem futott le és milyen hibával. Hiányos adatból
NE vonj le következtetést.

---

## Kimenet — ezt tedd elém

Rövid (kb. 20–30 soros) összefoglaló, MAGYARUL, ebben a struktúrában.
Ha egy szakaszhoz nincs adat, írd oda, hogy „nincs" — ne hagyd ki.

**⚠️ Figyelmeztetések** *(ha van ilyen, ez legyen ELÖL — különben hagyd ki a szakaszt)*
- detached HEAD / nem pusholt commitok / behind állapot
- nem üres stash
- progress.md és git log dátum-eltérése
- ellentmondás a progress.md és a git status között
- nem futtatott parancs / hiba

**Projekt állapota** — a progress.md átadási pontja szerint, egy bekezdés.

**Git állapot** — branch, ahead/behind, stash (van/nincs, hány db).

**Utolsó commitok** — a releváns 4–6 sor, dátummal.

**Uncommitted munka** — igen/nem. Ha igen: fájlonként név + hány sor változott,
staged és unstaged bontásban. Migrációs fájlt külön kiemelve.

**Környezet** — mely Docker-szolgáltatások futnak.

**Következő teendő** — a progress.md szerint. Ne okoskodj hozzá, ne bővítsd ki:
idézd vagy pontosan summázd, amit a fájl mond. Ha a fájl nem mond egyértelműt,
azt írd: „a progress.md nem jelöl ki egyértelmű következő lépést".

**Kiegészítő docs** — melyik létezik a 4-ből.

Az összefoglaló után ÁLLJ MEG. Ne kérdezz rá, hogy folytathatod-e — csak várd meg,
mit mond Szolke.