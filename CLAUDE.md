# Munkamódszer és projekt-kontextus

## Indulás előtt — mindig olvasd el ezt is
- [docs/progress.md](docs/progress.md): mit csináltunk eddig (lépésenként, commit-hash-ekkel),
  mi van hátra, architekturális konvenciók, ismert nyitott pontok, demo bejelentkezés.
  Egy-egy nagyobb lépés (commit-csoport) végén EZT A FÁJLT IS FRISSÍTSD — ez tartja a
  következő munkamenetet (vagy kontextus-vesztés utáni folytatást) gyorsan tájékozottnak,
  anélkül hogy a git logból/kódból kelljen visszafejteni az állapotot.
- [docs/er-model.md](docs/er-model.md): a tábla-/mezőszintű adatmodell terve.

## Kommunikáció
- Magyar nyelven kommunikálj, érthetően; technikai zsargon esetén adj rövid magyarázatot.
- Mielőtt parancsot futtatsz vagy fájlt módosítasz, írd le röviden, mit és miért csinálsz.

## Lépésenkénti haladás
- Egy logikai egységnyi módosítás/parancs után állj meg, és kérj visszajelzést, mielőtt továbblépsz.
- Ha egy feladat több lépésre van bontva a promptban, MINDEN köztes megállásnál állj meg és kérj visszajelzést — akkor is, ha a következő lépés triviálisnak tűnik. Ne csináld végig egyben a több lépésre bontott feladatot; a köztes jóváhagyás a lényeg, nem formalitás.
- Kockázatos lépések előtt (adatbázis-migráció, konténer törlése, force push, .env módosítás) MINDIG kérj explicit megerősítést.
- Ha egy parancs hibát ad, ne találgass: kérd el a pontos hibaüzenetet/logot, és csak az alapján javíts.

## Környezet
- A projekt WSL2 Ubuntu alatt fut, natív Linux fájlrendszeren: `/home/szolke/projects/erp-system`.
- SOHA ne dolgozz vagy hozz létre fájlokat a `/mnt/c/` vagy Windows-os elérési úton.
- Docker Compose alapú (`compose.yaml` a gyökérben): `laravel.test`, `pgsql` (PostgreSQL 18), `redis`, frontend szolgáltatások.
- Backend: http://localhost
- Frontend: http://localhost:5174

## Adatbázis
- Minden DB-módosítást migráción keresztül végezz, SOHA kézi SQL-lel a konténerben.

## Kódminőség
- Tiszta, jól kommentezett kód — különösen ott, ahol a magyar számlázási/NAV-specifikus logika nem magától értetődő.

## Git / Commit
- Commit üzenetek angolul, rövidek, tárgyilagosak (pl. "Add company model and migration").
- NE commitolj és NE pusholj automatikusan anélkül, hogy előtte jeleznéd, mit fogsz commitolni.
- Force push csak explicit jóváhagyással.
- Minden commit-csoport végén a `docs/progress.md` a TÉNYLEGES commit-hasheket kapja (ne "folyamatban" jelzést, ne hiányos hasht). Commit után mutass `git log --oneline`-t megerősítésül, hogy a napló a valós git-állapotot tükrözi.

## Érzékeny adatok
- NAV technikai felhasználó adatai, SimplePay kulcsok, `.env` tartalom SOHA ne kerüljön commitba, és ne íródjon ki nyersen terminálba/logba.
- Ha ilyesmi szükséges egy hibakereséshez, kérj el csak a releváns, anonimizált részletet.

## Architekturális döntések
- Ha több megoldási út is létezik, vázold röviden az alternatívákat, javasolj egyet indoklással, de a végső döntést hagyd a felhasználóra, ha a választás architekturális jelentőségű.

## Projekt-kontextus (mindig tartsd észben)
- Multi-company architektúra
- RBAC (role-based access control)
- NAV Online Számla integráció
- SimplePay integráció
- Jelezz, ha egy implementációs döntés ütközne ezekkel az alapelvekkel.
- Route model bindinget használó új végpontoknál kötelező az `EnforcesCompanyScope` minta (`assertBelongsToCurrentCompany()`) — a binding az `EnsureCompanyContext` middleware előtt fut, a globális scope önmagában nem elegendő. Részletesen: `docs/progress.md` Architekturális konvenciók.
- Token-auth (külső kliensek / mobil): Sanctum Bearer token (`POST /api/auth/token`) párhuzamosan él a SPA cookie-session authhal; az `auth:sanctum` middleware mindkettőt kezeli. **Új middleware- vagy handler-kód NE hívjon `$request->session()` közvetlenül `$request->hasSession()` guard nélkül** — Bearer token kérésnél Sanctum nem indít session-t, a hívás `RuntimeException`-t dob. Cég-kontextus token-úton: `X-Company-Id` header → (session, ha van) → `users.default_company_id` fallback. Token soha nem jár le — kizárólag `POST /api/auth/token/revoke` érvényteleníti.

## Bizonytalanság kezelése
- Ha valamit nem tudsz biztosan (pl. NAV Online Számla 3.0 XML-séma aktuális részletei, SimplePay API aktuális végpontjai), mondd meg egyértelműen, és ha szükséges, nézz utána friss dokumentációban — ne találgass.