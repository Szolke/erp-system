---
name: hol-tartunk
description: Felveszi a fonalat egy új sessionben — elolvassa a CLAUDE.md-t és a
  progress.md-t, lekéri a git logt és a git statust, majd rövid összefoglalót ad:
  hol tart a projekt, van-e uncommitted munka, és mi a következő teendő.
---

Olvasd el a következő fájlokat és futtasd a következő parancsokat EBBEN A SORRENDBEN.
Ne találgass, ne hozz döntést — csak gyűjtsd össze a tényeket és tedd elém.

## 1. CLAUDE.md — munkamódszer és architektúra

Olvasd el a `/home/szolke/projects/erp-system/CLAUDE.md` fájlt. Ez adja a kommunikációs
stílust, a git-konvenciókat és az architekturális elveket, amelyek MINDEN munkamenetre
érvényesek.

## 2. docs/progress.md — aktuális állapot

Olvasd el a `/home/szolke/projects/erp-system/docs/progress.md` fájlt. Különösen figyelj:
- Az "Utolsó frissítés" sorra (fejléc alatt)
- A "Még hátravan" szakasz ELSŐ alszakaszára (ha van "Következő lépés" / "ÚJ MUNKAMENET
  EZZEL KEZDJEN" jelölés, az a legsürgetőbb)
- A "Legutóbbi állapot" összefoglaló sorra (Kész modulok szakasz végén)

## 3. git log — legutóbbi commitok

```bash
git -C /home/szolke/projects/erp-system log --oneline -15
```

## 4. git status — uncommitted változások

```bash
git -C /home/szolke/projects/erp-system status
```

**KRITIKUS:** Ha a git status BÁRMILYEN módosított vagy untracked fájlt mutat (különösen
a `frontend/` vagy `backend/` alatt), azt EXPLICIT ki kell emelni. Ez egy korábbi session
félbehagyott munkája lehet — figyelmen kívül hagyni tilos.

Ha a progress.md "kész" vagy "commitolva" állapotot ír, de a git status uncommitted kódot
mutat: jelezd az ELLENTMONDÁST — ne simítsd el.

## 5. Kiegészítő dokumentumok — csak jelezd, ne olvasd el

Ellenőrizd, hogy léteznek-e ezek a fájlok (ne olvasd el őket, csak tudd, hogy ott vannak):
- `docs/deploy.md` — élesítési szekvencia
- `docs/requirements.md` — rendszerkövetelmények
- `docs/backup.md` — PDF backup eljárás
- `docs/er-model.md` — tábla-/mezőszintű adatmodell

## Kimenet — ezt tedd elém

Egy rövid (kb. 15–25 soros) összefoglaló, MAGYARUL, ebben a struktúrában:

**Projekt állapota** (a progress.md átadási pontja szerint — egy bekezdés)

**Utolsó commitok** (a git log alapján — csak a releváns 4–6 sor)

**Uncommitted munka** (igen/nem; ha igen: pontosan mely fájlok és mit tartalmaznak a
git status szerint — ha ellentmond a progress.md-vel, azt is jelezd)

**Következő teendő** (a progress.md szerint — ne okoskodj hozzá, idézd vagy summázd
pontosan, amit a fájl mond)

**Kiegészítő docs** (melyik létezik a 4 fájlból)
