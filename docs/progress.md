# Fejlesztési állapot

> Ezt a fájlt mindig frissítsd egy-egy nagyobb lépés (commit-csoport) végén.
> Célja: egy új munkamenet gyorsan tájékozódjon az aktuális állapotról.
>
> Három forrás együtt adja a teljes képet:
> - **progress.md** (ez) — aktív állapot: konvenciók, nyitott pontok, checklist, demo adatok
> - **[CHANGELOG.md](CHANGELOG.md)** — történelmi lépés-napló: lépések leírása, commit hash-ek, fájllisták
> - **[er-model.md](er-model.md)** — részletes tábla-/mezőszintű adatmodell

Utolsó frissítés: 2026-07-07, API tesztelő elkészítve (`2e768a4`).

## Kész modulok (összefoglaló)

A részletes fejlesztési napló commit hash-ekkel és fájllistákkal: **[CHANGELOG.md](CHANGELOG.md)**

Megvalósított főbb területek:
- Auth (Sanctum SPA), RBAC (csoport + user override, `Gate::before`, `PermissionChecker`), szuperadmin szerep + bootstrap
- Törzsadatok (partner, termék, cég), cégszintű beállítások, egyéni mezők (JSONB hibrid)
- Számlázás (gapless sorszámozó, sztornó-lánc, dupla-sztornó védelem), nyugta
- NAV Online Számla 3.0 (queue job, toggle), SimplePay (start + IPN + refund → sztornó)
- MNB árfolyam-lekérdező (ütemezve, supervisor), audit log
- PDF generálás + archiválás + kontrollált újragenerálás (jogi megőrzéssel)
- i18n (HU/EN/DE), dark mode, API dokumentáció (Scribe, 73 endpoint)
- API tesztelő (`/settings/api-tester`): Scribe spec explorer, élő API hívás (path/query/body paraméterek), kétszintű megerősítő modal (normál write vs. visszafordíthatatlan: cancel/refund/regenerate-pdf/DELETE)
- Felhasználói wiki (`docs/wiki/`, HU+EN, 17 Markdown-fájl, 8 fejezet)
- Bizonylatlista (UNION ALL, szűrők), sidebar (összecsukó, accent-szín 18 paletta, CSS-változók)
- Toast értesítések, pagination UI, szuperadmin-bootstrap (`erp:create-superadmin`)

**Legutóbbi állapot:** SA-BOOT = `64588f8` — teljes suite: **66 teszt / 190 assertion, mind zöld**

## Még hátravan

Minden tervezett funkció implementálva van. Hátramaradó teendők kizárólag sandbox-tesztelés jellegűek (valódi hitelesítő adatok szükségesek):

- **SimplePay sandbox-tesztelés** — start URL struktúra (1 vs. több callback URL), refund API pontos request/response formátum, IPN visszajelzés refundra. Valódi merchant-adatokkal kell ellenőrizni élesítés előtt.
- **NAV sandbox-tesztelés** — `vatExemption` kódok (AAM/TAM stb.) XSD-konformitása `NavXmlBuilder`-ben.

## Élesítés előtti checklist

Ezek a pontok **blokkolják** az éles üzembe helyezést. Mind addig nyitott, amíg be nem jelölve.

- [x] **Szuperadmin-seed letiltása élesben** — `DatabaseSeeder` fix `test@example.com` / `password` kombinációt hoz létre `is_superadmin=true`-val. **MEGOLDVA (SA-BOOT):** production env-ben a demo-seed nem fut (`App::environment('local', 'testing')` feltétel); éles admin a `php artisan erp:create-superadmin` paranccsal hozható létre. Részletek: Nyitott pont #6.
- [ ] **PDF-tár Docker-jogosultság** — ha a `storage/app/private/documents/` könyvtárat root hozza létre, a `sail` user nem tud írni bele (500 hiba). Frissen klónozott repo / új szerver esetén egyszer: `docker compose exec laravel.test chown -R sail:sail storage/app/private/documents`. Részletek: Nyitott pont #7.
- [ ] **PDF-tár külső backup** — `storage/app/private/documents/` gitben nincs (szándékos). Élesítésnél kötelező a napi külső mentés (S3 vagy egyenértékű) — a tárolt PDF-ek jogi bizonyíték-értékűek. Részletek: Nyitott pont #7.
- [ ] **NAV sandbox-tesztelés** — `vatExemption` kódok (AAM/TAM stb.) XSD-konformitása `NavXmlBuilder`-ben valódi NAV sandbox hitelesítő adatokkal ellenőrzendő. Részletek: Nyitott pont #1.
- [ ] **SimplePay sandbox-tesztelés** — start URL struktúra (1 vs. több callback URL), refund request/response formátum, IPN visszajelzés refundra, valódi merchant-adatokkal. Részletek: Nyitott pont #3, #4.
- [ ] **`.env` éles értékek** — `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, DB jelszó, `NAV_*`, `SIMPLEPAY_*` kulcsok cserélve fejlesztési értékekről (érzékeny adat — commitba soha ne kerüljön). Részletek: Nyitott pont #0.

## Tervezett jövőbeli fejlesztések

Ezek tervek, nem mai feladatok — rögzítve, hogy egy-egy munkamenet ne találja ki újra.

- **Hibajelentő / support-ticket modul:** a rendszer felhasználói hibajelentést vehetnek fel közvetlenül az ERP-ből; önálló közepes modul (saját tábla, státuszok, RBAC jogosultságok) — részletezés később szükséges.
- **Konfiguráció átláthatóbbá tétele:** NEM külön `conf.php` (ütközne a Laravel `.env`/`config` rendszerével, és érzékeny adatot csábítana commitba), hanem dokumentált `.env.example` + egy `config/erp.php` a projekt-specifikus, nem-titkos beállításoknak — cél: egy helyen, Laravel-konform módon konfigurálható rendszer.
- ~~**Fejlesztői/felhasználói dokumentáció (wiki):**~~ **KÉSZ (`47176b8`)** — `docs/wiki/` alatt 17 Markdown-fájl: `README.md` index + `hu/` és `en/` alkönyvtárban 8-8 oldal (bejelentkezés, számla/nyugta kiállítás, bizonylatok, fizetések, beállítások, jogosultságok, egyéb).
- **Wiki viewer az ERP frontendben (kereshető):** a `docs/wiki/` Markdown-tartalom megjelenítése a bejelentkezett felhasználók számára a React SPA-ban. Döntött irány: Vite `?raw` import (bundled), `react-markdown` renderer, kliens-oldali keresés mind a 8 oldalon, locale-érzékeny (`hu/` vs `en/` a `TranslationContext` alapján), `/wiki` útvonal sidebar-menüponttal. Backend-változtatás nem szükséges. A wiki-tartalom (`docs/wiki/`) minden nagyobb fejlesztés után frissítendő.

## Architekturális konvenciók (amit egy új munkamenetnek tudnia kell)

- **Multi-tenant szűrés**: minden cég-szintű modell a `BelongsToCompany` trait-et használja (`app/Models/Concerns/BelongsToCompany.php`) — globális scope a `CurrentCompany` singletonon keresztül (`app/Support/CurrentCompany.php`), amit az `EnsureCompanyContext` middleware tölt fel kérésenként. Service/job kontextusban (ahol nincs middleware) explicit `withoutGlobalScope('company')`-t kell használni, ha a company_id-t kézzel adjuk meg (lásd `InvoiceNumberGenerator`, `PermissionChecker`).
- **RBAC**: jogosultság-kulcsok `modul.művelet` formában (`app/Models/Permission`, seedelve `PermissionSeeder`-ből). Controllerben `$this->authorize('invoice.cancel')` vagy `$user->can(...)` — mindkettő a `Gate::before`-ba kötött `PermissionChecker`-en megy át (`AppServiceProvider::boot()`). **Kétféle jog-feloldási út létezik, és a superadmin-szabályt MINDKETTŐN konzisztensen kell tartani:** (1) `Gate::before` (`AppServiceProvider`) — a backend `authorize()`/`can()` hívásait rövidre zárja; (2) `PermissionChecker::effectivePermissionKeys()` — a `/api/me` endpoint ezen keresztül adja vissza a frontend `permissions` tömbjét (a `can()` JS-függvény erre épít). Ha csak az egyikbe kerül be a shortcut, a másik útvonalon üres/hiányos lista jön vissza (frontend menüpont-eltűnés, vagy fordítva: UI enged, backend tilt). Új globális jog-kivételt (pl. superadmin, read-only mód) mindig mindkét helyen kell implementálni.
- **Service réteg**: minden komolyabb üzleti logika (`InvoiceService`, `ReceiptService`, `PaymentStatusUpdater`, `NavXmlBuilder`, `SimplePayClient`) `app/Services/` alatt, controllerek vékonyak maradnak.
- **EnforcesCompanyScope** (cross-company szivárgás-védelem): minden controller metódusban, ahol route model binding cég-szintű modellt tölt be, kötelező meghívni `$this->assertBelongsToCurrentCompany($model)` az `EnforcesCompanyScope` traitből (`app/Http/Controllers/Concerns/`) — ha a modell `company_id`-je ≠ `CurrentCompany::id()`, 404-gyel válaszol. **Miért szükséges?** A route model binding az `EnsureCompanyContext` middleware *előtt* fut, ezért a globális scope önmagában nem elegendő: egy idegen cég rekordja URL-ben megadott ID-val betöltődhet, ha nincs explicit ellenőrzés. Érintett kontrollerek (9 db): `PartnerController`, `ProductController`, `InvoiceController`, `ReceiptController`, `GroupController`, `DocumentSeriesController`, `CustomFieldDefinitionController`, `PaymentController`, `SimplePayController`. Minden **új** végpontnál, ahol binding-ot használunk, ezt a mintát kötelező követni.
- **DB-default mezők**: `Model::create()` után **mindig** `->refresh()` kell, ha a válaszban DB-szintű default értéket (pl. `is_active`, `nav_status`) akarunk visszaadni — enélkül `null` jön vissza a friss objektumból. Ez a hiba már kétszer előjött (Product/Partner, majd Invoice), `refresh()`-sel javítva.
- **Seederek**: `PermissionSeeder`/`VatRateSeeder`/`PaymentMethodSeeder` = valódi katalógus-adat, mindig fusson. `DemoDataSeeder` = reprodukálható teszt-sandbox (1 cég, 2 csoport, override-ok, 1 termék/partner, NAV teszt+éles dummy hitelesítés) — idempotens (`updateOrCreate`/`sync`), bármikor újrafuttatható.
- **Worker/scheduler**: a Sail image-et publikáltuk (`backend/docker/8.5/`, NEM a `vendor/`-ból épül többé), a `supervisord.conf` futtat egy `queue-worker` (NAV job) és egy `scheduler` (MNB) processzt — ezeknek menniük kell automatikusan, nem kell kézzel `queue:work`-öt indítani.
- **`scribe:generate` — mindig `--user sail` flaggel:** `docker compose exec --user sail laravel.test php artisan scribe:generate`. Ha root-ként fut (flag nélkül), a generált könyvtárak (`storage/app/private/scribe/`, `.scribe/`, `public/vendor/scribe/`, `resources/views/scribe/`) `root:root` tulajdonba kerülnek (`700` jogokkal), a PHP-FPM `sail` user nem tudja olvasni → `file_exists()` visszaad `false`-t → 503 válasz. Ha root-tulajdonú fájlok keletkeztek: `docker compose exec laravel.test chown -R sail:sail .scribe storage/app/private/scribe public/vendor/scribe resources/views/scribe`.

## Demo bejelentkezés (helyi teszteléshez)

- `test@example.com` / `password`
- Felhasználó neve: **Admin**, `is_superadmin = true` — minden jogot megkap, nem törölhető/letiltható
- Aktív cég: "Demo Kft." (tax_number `11111111-1-42`)
- A demo user 2 csoportban van ("Pénzügy": invoice/receipt/payment jogok; "Törzsadatkezelő": product/partner/company/user/group/document_series jogok), plusz 1 explicit override (`invoice.cancel` allow) — RBAC-teszteléshez.
- Bizonylat-sorozatok: `SZ` (számla), `NY` (nyugta), `SZSZT` (sztornó számla), `NYSZT` (sztornó nyugta). Formátum: `PREFIX-ÉÉÉÉHH-000001`.

## Nyitott pontok / ismert hiányosságok

0. **`.env` éles értékek cseréje** — A fejlesztési `.env` értékek (`APP_ENV=local`, `APP_DEBUG=true`, fejlesztési `APP_KEY`, tesztelési DB jelszó, NAV sandbox hitelesítők, SimplePay sandbox merchant-adatok) cserélendők éles megfelelőikre élesítés előtt: `APP_ENV=production`, `APP_DEBUG=false` (hibaüzenetek ne szivárogjanak ki), erős egyedi `APP_KEY`, biztonságos DB jelszó, valódi NAV technikai felhasználó + aláírási kulcs, valódi SimplePay merchant ID + titkos kulcs. Ezek az értékek **SOHA nem kerülhetnek commitba** — kizárólag a szerveren a `.env` fájlban tárolhatók.

9. **Opcionális jövőbeli fázis — nyitott/kézzel fizethető nyugta:** Jelenleg a nyugta "azonnal fizetett" modell szerint működik — a `receipts` táblán nincs `payment_status` mező (az `invoices` táblán van: `open`/`partial`/`paid`), a `PaymentController` és `PaymentStatusUpdater` csak számlát kezel. A `Receipt::payments(): MorphMany` reláció deklarált a modellen, de jelenleg használaton kívüli. Ha a jövőben szükségessé válik a nyitott/részben fizetett nyugta, az szükségessé teszi: (a) migrációt (`receipts.payment_status` mező — **kockázatos lépés**), (b) `PaymentController`/`PaymentStatusUpdater` kiterjesztést Receiptre, (c) `ReceiptDetailPage` fizetés-szekciót, (d) opcionálisan `CompanySetting::RECEIPT_AUTO_SETTLE` kapcsolót. Jelenlegi állapot a design szándék: a nyugta kiállítása egyben lezárja a fizetési folyamatot.

1. **NAV `vatExemption` case kódok** (AAM/TAM stb. a `vat_rates.nav_code`-ban) — a pontos XSD enumerációt nem sikerült közvetlenül kinyerni, NAV sandbox ellen kell ellenőrizni `NavXmlBuilder`-ben élesítés előtt.
2. **`tax_number` formátum** — ~~Jelenleg nincs validáció~~ **MEGOLDVA** (`c2aaee2`): `UpdateCompanyRequest` + `StoreCompanyRequest` regex enforcolja (`^\d{8}-\d-\d{2}$`). Partner marad szabad formátum (külföldi cégekhez).
3. **SimplePay `url` mező** — a start-kérésben az egyetlen `url` mezőt használjuk visszairányításra; nem 100%-osan megerősített, hogy SimplePay nem külön success/fail/cancel/timeout URL-eket vár-e. Sandbox-tesztelés valódi merchant-adatokkal szükséges élesítés előtt.
4. **SimplePay refund API** — a `SimplePayClient::refund()` implementáció a v2 SDK forrása alapján készült (`refundTotal`, `transactionId` mezők, `/payment/v2/refund` endpoint), de az egzakt request/response struktúra és hogy van-e IPN visszajelzés refundra, sandbox-teszteléssel kell megerősíteni élesítés előtt.
5. **Nincs draft→issue számla-workflow** — a `POST /api/invoices` azonnal `issued` állapotban, lefoglalt sorszámmal hozza létre a számlát (tudatos egyszerűsítés). Ha draft-szerkesztés válik szükségessé, az `invoice_number` oszlopot nullable-re kell migrálni.
6. **Produkciós szuperadmin-seed kockázat** — ~~`DatabaseSeeder` fix `test@example.com` / `password` kombinációval hoz létre `is_superadmin=true` felhasználót. Ez fejlesztési/demo környezetben rendben, de **élesítés előtt kötelező kezelni**~~ **MEGOLDVA (SA-BOOT):** `DatabaseSeeder` a demo usert és a `DemoDataSeeder`-t `App::environment('local', 'testing')` feltétel mögé kapja — production env-ben nem fut. Első éles admin létrehozása: `php artisan erp:create-superadmin` (interaktív, jelszó nem kerül logba).
7. **PDF-ek nem gitben — backup kötelező** — a `storage/app/private/documents/` mappa `.gitignore` által kizárt (szándékos: bináris fájlok a gitben nem célszerű). Élesítésnél biztosítani kell a külső backup-ot (pl. S3 tükrözés, napi mentés), mert a tárolt PDF-ek jogi bizonyíték-értékűek. A `Storage::disk('local')` Laravel 11-ben `storage/app/private/`-ra mutat (Laravel 10-től eltérően, ahol `storage/app/` volt). **Docker-jogosultság**: ha `docker compose exec laravel.test` (root-ként) hoz létre először könyvtárat a `documents/` alatt, az `root:root` tulajdonban lesz → a `sail` user (PHP process) nem tud beleírni → 500 `UnableToCreateDirectory` hiba. Javítás: `docker compose exec laravel.test chown -R sail:sail storage/app/private/documents && chmod -R 755 storage/app/private/documents`. Frissen klónozott repo esetén ezt egyszer kell lefuttatni.
8. **Fallback-generált PDF eltérhet az eredetitől** — ha egy bizonylat kiállításakor a PDF-mentés sikertelen volt (lemez tele, jogosultság stb.) és a `tryPersistPdf()` hibaágon futott, a következő letöltési kísérletnél a fallback generálja a PDF-et az aktuális sablonnal. Ha időközben a Blade sablon, a céglogó vagy a fordítások megváltoztak, az utólag generált PDF vizuálisan eltérhet az eredetileg kiállítotttól. Az `AuditLogger` `invoice.pdf_persist_failed` / `receipt.pdf_persist_failed` eseményei jelzik az érintett bizonylatokat. **Részlegesen megoldva (PDF-R lépés):** szándékos, auditált újragenerálásra van dedikált végpont (`regenerate-pdf`) — a sablonhiba-javítás helyes útja ez, nem a fallback. A fallback (letöltéskor generál) továbbra is él régi bizonylatok és persist-failed esetekre.

## Hogyan fuss neki gyorsan egy új munkamenetben

```bash
cd /home/szolke/projects/erp-system   # MINDIG innen futtass docker compose-t, ne a backend/-ből
docker compose ps                      # 4 konténer fusson: laravel.test, pgsql, redis, frontend
docker compose exec laravel.test php artisan migrate:status
docker compose exec laravel.test php artisan route:list --path=api
```
