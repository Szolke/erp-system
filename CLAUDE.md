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
- A munka elején vázold röviden, mit tervezel; a végén foglald össze, mit csináltál. Az egyes parancsokat/fájlmódosításokat nem kell külön-külön előre bejelentened.

## Haladás és megállások
- Haladj végig a feladaton önállóan. Az apró döntéseket (komponens-tagolás, fájlszervezés, elnevezések) hozd meg magad a kódbázis meglévő mintái alapján, és a munka végén foglald össze, mit választottál.
- ÁLLJ MEG, ha valódi döntési pont merül fel: több járható út van, a választásnak érdemi következménye van, és nincs egyértelmű precedens a kódbázisban.
- ÁLLJ MEG, ha ellentmondást találsz a feladatleírás és a tényleges kód között. Ne oldd fel magadtól.
- Kockázatos lépések előtt MINDIG kérj explicit megerősítést: adatbázis-migráció, `.env` módosítás, konténer törlése, force push.
- Commit előtt MINDIG állj meg: írd le, mely fájlok kerülnek be és mi lesz a commit üzenet, majd várd meg a jóváhagyást.
- Ha egy parancs hibát ad, ne találgass: nézd meg a pontos hibaüzenetet/logot, és csak az alapján javíts.

## Környezet
- A projekt WSL2 Ubuntu alatt fut, natív Linux fájlrendszeren: `/home/szolke/projects/erp-system`.
- SOHA ne dolgozz vagy hozz létre fájlokat a `/mnt/c/` vagy Windows-os elérési úton.
- A host `npm` a Windows-oldali Node telepítésre mutat (`/mnt/c/...` alatt) — natív Linux útvonalon EISDIR/EPERM hibával elszáll. Ezért MINDEN frontend npm-műveletet (telepítés, csomag hozzáadása, build) a `frontend` konténerben futtass: `docker compose exec frontend npm install <csomag>`. Ez nem preferencia, hanem környezeti kényszer.
- Docker Compose alapú (`compose.yaml` a gyökérben): `laravel.test`, `pgsql` (PostgreSQL 18), `redis`, frontend szolgáltatások.
- A compose-projekt a repó GYÖKERÉBŐL fut. A `./vendor/bin/sail` a `backend/` alkönyvtárból nem indul ("Sail is not running") — a gyökérből futtasd, vagy használd a `docker compose exec laravel.test php artisan ...` formát.
- A `laravel.test` konténer NEM-ROOTKÉNT fut (`sail`, a host UID/GID-jén — l. `compose.yaml` `user:` + Dockerfile `USER sail`), ezért a `docker compose exec laravel.test php artisan ...` forma is `sail`-ként fut, és nem hagy root tulajdonú fájlt a bind-mountolt `backend/` alatt. Root-ot igénylő karbantartáshoz EXPLICIT kell kérni: `docker compose exec -u root laravel.test ...`.
- Backend: http://localhost
- Frontend: http://localhost:5174
- git checkout / bisect után mindig `docker compose restart frontend` build vagy teszt előtt (stale bind-mount, l. docs/requirements.md).
- A `.scribe/endpoints/*.yaml` és a `resources/views/scribe/index.blade.php` generált API-doksi (`php artisan scribe:generate` állítja elő) VERZIÓBAN MARAD — nem kézzel szerkesztendő, és szándékosan nincs `.gitignore`-olva, hogy a doksi elavulása látható legyen a diffben. Ha egy lépés új API-végpontot ad vagy meglévőt módosít, a commit ELŐTT futtasd a `scribe:generate`-et, és a generált fájlok legyenek a kód-commit részei. A generálás időnként zajos diffet ad (időbélyeg, sorrend) — ez elfogadott, nem hiba.

## Adatbázis
- Minden DB-módosítást migráción keresztül végezz, SOHA kézi SQL-lel a konténerben.

## Tesztelés
- Backend/unit teszteket futtass le magad; a zöld teszteredmény a commit előfeltétele.
- A böngészős, end-to-end végigjátszást a FELHASZNÁLÓ végzi manuálisan: a Sanctum SPA session-auth cookie `localhost`-domainhez van kötve, a konténerek belső IP-jéről nem authentikálható. Frontend-változásnál készíts rövid kézi tesztelési listát ahelyett, hogy magad próbálnád végigkattintani.

## Kódminőség
- Tiszta, jól kommentezett kód — különösen ott, ahol a magyar számlázási/NAV-specifikus logika nem magától értetődő.

## Git / Commit
- Commit üzenetek angolul, rövidek, tárgyilagosak (pl. "Add company model and migration").
- NE commitolj és NE pusholj automatikusan anélkül, hogy előtte jeleznéd, mit fogsz commitolni.
- Force push csak explicit jóváhagyással.
- Commit előtt ellenőrizd a `git status`-t: ha a munkakönyvtárban a feladathoz NEM tartozó módosítás is van, azt zárd ki a commitból, és jelezd.
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
- Jogosultság-feloldás KÉT úton történik: `Gate::before` (backend `authorize`/`can`) és `PermissionChecker::effectivePermissionKeys()` (`/api/me` → frontend `can()`). Superadmin-szabályt és minden globális jog-kivételt MINDKÉT helyen implementálni kell.
- Token-auth (külső kliensek / mobil): Sanctum Bearer token (`POST /api/auth/token`) párhuzamosan él a SPA cookie-session authhal; az `auth:sanctum` middleware mindkettőt kezeli. **Új middleware- vagy handler-kód NE hívjon `$request->session()` közvetlenül `$request->hasSession()` guard nélkül** — Bearer token kérésnél Sanctum nem indít session-t, a hívás `RuntimeException`-t dob. Cég-kontextus token-úton: `X-Company-Id` header → (session, ha van) → `users.default_company_id` fallback. Token soha nem jár le — kizárólag `POST /api/auth/token/revoke` érvényteleníti.

## Bizonytalanság kezelése
- Ha valamit nem tudsz biztosan (pl. NAV Online Számla 3.0 XML-séma aktuális részletei, SimplePay API aktuális végpontjai), mondd meg egyértelműen, és ha szükséges, nézz utána friss dokumentációban — ne találgass.