---
name: docs-sync
model: sonnet
effort: low
description: Frissíti a projekt dokumentációját egy befejezett lépés/commit-csoport
  után. Használd, amikor "frissítsd a doksit", "dokumentáld a lépést" vagy "update
  docs" hangzik el, illetve amikor egy commit-csoport után a docs/progress.md és
  docs/CHANGELOG.md a VALÓDI commit-hash-ekre szorul. Kiolvassa a hash-eket a git
  logból (SOHA nem találja ki), a meglévő formátumot követve frissíti a releváns
  .md fájlokat, majd MEGÁLL a doksi-commit előtt jóváhagyásért. Nem pushol.
  A felhasználói wikit és a fejlesztői kézikönyveket (deploy/requirements/backup)
  NEM ez a skill kezeli, hanem a kezikonyv-szinkron.
---

Frissítsd a dokumentációt egy befejezett lépés után. Kövesd a lépéseket EBBEN A
SORRENDBEN. A CLAUDE.md és a docs/progress.md szabályai felülírják ezt, ha ütköznek.

**KRITIKUS — vasszabályok:**
- Commit-hash-t SOHA ne találj ki. Minden hash a `git log`-ból származik.
- NE commitolj és NE pushol j jóváhagyás nélkül. Force push tilos.
- Érzékeny adat (NAV technikai felhasználó, SimplePay kulcs, .env tartalom) SOHA
  ne kerüljön a dokumentációba.
- Csak `.md` doksit szerkeszd (docs/ + gyökér-doksi). Kód NEM módosul itt.
- /mnt/c/ vagy Windows-úton dolgozni tilos.

## 1. A dokumentálandó commitok azonosítása

```bash
git -C /home/szolke/projects/erp-system log --oneline origin/main..main
```

Állapítsd meg, mely commit(ok) tartoznak a mostani lépéshez, és jegyezd fel a
TÉNYLEGES rövid hash-üket. Ha nem egyértelmű, mely commitok tartoznak ide,
KÉRDEZZ — ne találgass.

## 2. A meglévő formátum beolvasása (kötelező)

Olvasd el a `/home/szolke/projects/erp-system/docs/progress.md` és a
`/home/szolke/projects/erp-system/docs/CHANGELOG.md` fájlok VÉGÉT (utolsó
bejegyzések). Pontosan azt a szerkezetet és stílust kövesd: fejlécek, dátum-/
"Utolsó frissítés"-formátum, hash-hivatkozás módja, felsorolás-stílus. Új
formátumot NE vezess be.

## 3. Az érintett .md fájlok meghatározása

- `docs/progress.md` — MINDIG. Ez az igazságforrás az aktuális állapotra. Új
  bejegyzés a lépésről (mit csináltunk, kulcs-döntések, tesztszám ha releváns),
  a valódi hash(ek)kel. Ha van "Utolsó frissítés" / "Következő lépés" /
  "ÚJ MUNKAMENET EZZEL KEZDJEN" jelölés, azt is aktualizáld.
- `docs/CHANGELOG.md` — MINDIG. Rövid, tárgyilagos sor(ok) ugyanerről.
- `docs/er-model.md` — CSAK ha a lépés a sémát érintette (új tábla / oszlop / FK
  / index). Frissítsd a tábla-/mezőszintű leírást.
- Felhasználói wiki (`docs/wiki/`) és a fejlesztői kézikönyvek (`docs/deploy.md`,
  `docs/requirements.md`, `docs/backup.md`): ezeket a `docs-sync` NEM módosítja.
  Ez a `kezikonyv-szinkron` skill hatóköre (kézikönyv-audit, HU–EN paritás, 3 fázisú
  jóváhagyás-kapu). Ha a mostani lépés érinti a végfelhasználói kézikönyvet vagy egy
  dev-manualt, azt itt CSAK JELEZD a záró összefoglalóban ("a kézikönyv frissítést
  igényelhet — futtasd a kezikonyv-szinkron skillt"), de ne írd át ezeket a fájlokat.

## 4. A bejegyzések megírása

Tömör, tárgyilagos MAGYAR leírás a progress.md stílusában. A commit-üzenetek
angolok maradnak, azokra hivatkozhatsz. A hash-ek az 1. pontban kiolvasott
VALÓDI értékek. Érzékeny adatot NE másolj be.

## 5. Ellenőrzés és megállás

```bash
git -C /home/szolke/projects/erp-system log --oneline -5
```

Igazold, hogy a doksiba írt hash-ek megegyeznek a tényleges commitokkal. Mutasd
a szerkesztett .md fájlok diffjét:

```bash
git -C /home/szolke/projects/erp-system diff -- docs/
```

**KRITIKUS:** ÁLLJ MEG. Javasolj EGY külön doksi-commitot rövid ANGOL üzenettel
(pl. "Document <lépés neve>"), de NE commitolj jóváhagyás nélkül. NE pushol j.

## 6. Jóváhagyás után

Csak explicit jóváhagyásra commitold a doksi-változást a javasolt üzenettel,
majd megerősítésül:

```bash
git -C /home/szolke/projects/erp-system log --oneline -3
```

Írd ki a doksi-commit tényleges rövid hash-ét.
