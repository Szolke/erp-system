# NAV modul — beküldés-logolás feltáró audit

> READ-ONLY feltárás. Kódot, migrációt, tesztet ez a munkamenet nem módosított.
> Készült: 2026-07-20. Forrás: `backend/` (Laravel 13 / PHP 8.5), `frontend/` (React/Vite).

## 1. A NAV modul feltérképezése

| Fájl | Felelősség |
|---|---|
| `app/Modules/Definitions/NavModule.php` | Modul-descriptor: kulcs `nav`, függ `invoicing`-tól, jogkulcsok `invoice.send_nav` + `nav.view_log`, `settingsRoute()` → `/company#section-nav`. |
| `app/Jobs/SendInvoiceToNavJob.php` | A teljes beküldési folyamat vezérlője — queue job, `ShouldQueue`, `tries=3`, `backoff=300` (5 perc). |
| `app/Services/Nav/NavXmlBuilder.php` | `Invoice` modellből NAV `invoiceData.xsd`-konform `SimpleXMLElement`-et épít (CSAK a számla-adat, hitelesítő adat NINCS benne). |
| `app/Services/Nav/NavReporterFactory.php` | `CompanyNavCredential`-ból a vendor `NavOnlineInvoice\Config`/`Reporter` objektumot építi fel (itt kerül be a jelszó/kulcs a memóriába). |
| `vendor/pzs/nav-online-invoice/src/NavOnlineInvoice/*` | Harmadik féltől származó kliens-lib (`Reporter`, `Connector`, `BaseRequestXml`, kivétel-osztályok). Nem projekt-kód, de a beküldési lánc része. |
| `app/Enums/NavStatus.php` | Invoice-szintű állapot: `not_applicable\|pending\|sent\|confirmed\|error`. |
| `app/Enums/NavSubmissionStatus.php` | Log-sor szintű állapot: `success\|error` (csak kettő — ld. 2. pont). |
| `app/Enums/NavEnvironment.php` | `test\|production`. |
| `app/Models/CompanyNavCredential.php` + migráció `2026_06_30_100003_...` | Céges NAV technikai felhasználó adatai (login/password/signKey/exchangeKey), `encrypted` cast + `#[Hidden]`. |
| `app/Models/NavSubmissionLog.php` + migráció `2026_06_30_100019_create_nav_submission_logs_table.php` | **Már létező** beküldés-napló tábla (ld. 2. pont — nem üres feladat, hanem bővítendő). |
| `app/Models/Invoice.php:94-96` (`navSubmissionLogs()`) + migráció `2026_06_30_100017_create_invoices_table.php:32-35` | `invoices.nav_status` / `nav_transaction_id` / `nav_sent_at` — a számla JELENLEGI NAV-állapota (nem történeti). |
| `app/Http/Controllers/Api/CompanyNavCredentialController.php` | Hitelesítő-kezelő CRUD + aktív környezet váltás (`routes/api.php:95-99`, `module:nav` middleware). |
| `app/Http/Resources/InvoiceResource.php:57` | `nav_status` mező a JSON-válaszban. |
| `app/Services/InvoiceService.php:67,156` | A job dispatch-pontjai: számla kiállításkor (`CREATE`) és sztornózáskor (`STORNO`). |
| `app/Http/Controllers/Api/DashboardController.php:42-62` + `app/Services/DashboardService.php:154-170` | Dashboard `nav_status` widget — kétlépcsős gating (modul KI → `module_disabled`, jog nélkül → `no_permission`), `error_count` + `last_sent_at`. |
| `frontend/src/pages/CompanyPage.jsx` (`NavSection`) | Hitelesítő-kezelő UI, környezetváltó modal. |
| `frontend/src/pages/DashboardPage.jsx` | Az EGYETLEN frontend hely, ahol `nav_status` egyáltalán megjelenik — de csak összesített `error_count`/`last_sent_at`, invoice-szinten SEMMI. |

**Végpontból-végpontig folyamat:**
1. `InvoiceService::create()` / `cancel()` → `SendInvoiceToNavJob::dispatch($invoice->id, 'CREATE'|'STORNO')` (`InvoiceService.php:67,156`).
2. A job **aszinkron** fut (queue worker). `handle()`-ben (`SendInvoiceToNavJob.php:39-41`) `Invoice::withoutGlobalScope('company')->findOrFail()` — mert queue-kontextusban nincs `CurrentCompany`.
3. Modul-gate: `ModuleResolver::isAllowed('invoice.send_nav', $invoice->company_id)` (sor 45) — ha nem, `nav_status=NotApplicable` + `Log::info` (sor 47).
4. Aktív hitelesítő keresés a számla `company.nav_environment`-jéhez (sor 59-62) — ha nincs, `nav_status=NotApplicable` + `Log::warning` (sor 69).
5. `NavXmlBuilder::build()` → `NavReporterFactory::make($credential)` → `Reporter::manageInvoice($xmlElement, $operation)`.
6. A vendor `Reporter::manageInvoice()` belül előbb `tokenExchange()`-et hív (saját HTTP-kérés), majd a tényleges `/manageInvoice` POST-ot — **csak a `transactionId` stringet adja vissza** (`Reporter.php:77-96`), a tényleges elfogadás/elutasítás eredménye NEM ebben a válaszban van.
7. Siker esetén `NavSubmissionLog::create()` (`status=success`) + `invoice->update(['nav_status'=>Sent, 'nav_transaction_id'=>..., 'nav_sent_at'=>now()])` (sor 92-104).
8. Hiba esetén `NavSubmissionLog::create()` (`status=error`, `error_message`) + `nav_status=Error`, majd `throw $e` — a queue újrapróbálja (max 3×, 5 perces backoff).
9. **A folyamat itt véget ér.** A NAV oldali tényleges validációs eredmény (`queryTransactionStatus`) lekérdezése SEHOL nincs meghívva — ld. 2. és 4. pont, ez a legsúlyosabb funkcionális hiány.

## 2. Jelenlegi naplózás felmérése

- **`Log::` hívás a NAV-útvonalon: csak 2 db, mindkettő a "beküldés ki sem megy" ágon.**
  - `SendInvoiceToNavJob.php:47` — `Log::info('NAV submission skipped: nav module disabled...', ['invoice_id', 'company_id'])`.
  - `SendInvoiceToNavJob.php:69` — `Log::warning('NAV submission skipped: no active credential...', ['invoice_id', 'invoice_number', 'company_id', 'environment'])`.
  - **A sikeres és a hibás TÉNYLEGES beküldési kísérlet (7-8. lépés) NEM ír a Laravel log-csatornára**, kizárólag a DB-be (`nav_submission_logs`). Aki a `storage/logs/laravel.log`-ot nézi hiba után, nem talál semmit — csak a DB-t vizsgálva derül ki.
- **Van dedikált DB-tábla:** `nav_submission_logs` (`invoice_id`, `attempt_number`, `request_xml`, `response_xml`, `status` enum(success/error), `error_message`, `created_at` — nincs `updated_at`). Ez MÁR MŰKÖDIK, minden `handle()`-hívás (minden retry-attempt is) külön sort ír, `attempt_number`-rel sorszámozva (`SendInvoiceToNavJob.php:79`).
- **Az `invoices` tábla NAV-mezői** (`nav_status`, `nav_transaction_id`, `nav_sent_at` — migráció `2026_06_30_100017...php:32-35`) **felülíródnak minden kísérletnél** — csak a JELENLEGI állapotot tükrözik, nincs `nav_error_message` oszlop az invoice-on (helyesen — az a log-táblában van). A history-t kizárólag a `nav_submission_logs` őrzi meg.
- **Nyers XML tárolás — FÉLREVEZETŐ jelenlegi állapot:**
  - `request_xml` = `NavXmlBuilder::build($invoice)->asXML()` (`SendInvoiceToNavJob.php:84-85`) — ez a NAV-nak ténylegesen elküldött invoice-adat XML, hitelesítő adat NINCS benne (jó).
  - `response_xml` **NEM a NAV valódi válasza**, hanem egy manuálisan összerakott string: `'transactionId: '.$transactionId` (sor 90). A vendor `Reporter` a tényleges válasz-XML-t nem is adja vissza `manageInvoice()`-ból (csak `Connector::getLastResponseXml()`-en át lenne elérhető, amit a projekt sehol nem hív — ld. lent). Az oszlopnév ("response_xml") tehát megtévesztő: jelenleg soha nem tárol valódi választ.
  - Hiba esetén `request_xml`/`response_xml` `null` marad, ha a kivétel MÉG az XML-építés előtt történt (pl. `NavXmlBuilder::build()` maga dob hibát) — ez rendben van, csak jelzésértékű.
- **Elnyelt hiba: nincs.** A `catch (Throwable $e)` ág mindig logol (DB-be) ÉS újra-dobja a kivételt (`throw $e;`, sor 118) — a queue retry-mechanizmus és a `failed_jobs` tábla is látja. **Ez jó minta**, nincs csendben elnyelt hiba a job szintjén.
- **`queryTransactionStatus` — NINCS SEHOL meghívva a projektben.** A vendor lib implementálja (`Reporter.php:159-164`), de a `grep` szerint kizárólag `vendor/pzs/nav-online-invoice/examples/queryTransactionStatus.php`-ban van rá hívás (dokumentáció-példa), az `app/` alatt nulla találat. Ez azt jelenti:
  - A `manageInvoice()` sikeres visszatérése (transactionId megvan) **csak azt jelenti, hogy a NAV átvette feldolgozásra** — nem azt, hogy el is fogadta. A NAV valós idejű feldolgozás után adhat `ABORTED` státuszt vagy ERROR/WARN szintű `businessValidationMessages`/`technicalValidationMessages`-t, amit **csak a `queryTransactionStatus(transactionId)` hívással lehetne lekérdezni**.
  - A rendszer jelenleg `nav_status=Sent`-re állítja a számlát, és ott is marad — a felhasználó zöld/rendben állapotot lát egy olyan számlánál is, amit a NAV a háttérben esetleg elutasított.
- **Retry:** `tries=3`, `backoff=300` (Laravel queue-szintű, `SendInvoiceToNavJob.php:27-28`). Minden újrapróbálkozás új `attempt_number`-rel külön `nav_submission_logs` sort ír — a sikertelen kísérletek egyenként láthatók a DB-ben, csak épp semmilyen felület nem olvassa ki őket (ld. 4c).

## 3. Biztonsági ellenőrzés

**Nem találtam aktív titok-szivárgást.** Részletek:

- `CompanyNavCredential` (`app/Models/CompanyNavCredential.php:20-30`): `nav_login`/`nav_password`/`nav_signing_key`/`nav_exchange_key` mind `encrypted` cast + `#[Hidden(...)]` attribútum → soha nem szerializálódik JSON-ba, `toArray()`/`toJson()` kihagyja.
- `CompanyNavCredentialController::toPublic()` (`CompanyNavCredentialController.php:222-231`) kizárólag `has_login`/`has_password`/`has_signing_key`/`has_exchange_key` boolokat ad vissza az API-válaszban — az érték soha nem megy ki.
- A `nav_submission_logs.request_xml` mezőbe kerülő XML-t a projekt saját `NavXmlBuilder` építi — ez KIZÁRÓLAG számla-adatot tartalmaz (`app/Services/Nav/NavXmlBuilder.php`, teljes fájl átnézve), hitelesítő adat nincs benne. **A vendor lib által ténylegesen NAV-nak küldött teljes envelope** (`BaseRequestXml::addUser()`, `vendor/.../BaseRequestXml.php:85-95`) — ami `<login>`, SHA-512 `<passwordHash>` és SHA3-512 `<requestSignature>` mezőket tartalmaz — **soha nem kerül elő a projekt kódjából**: a `Connector::getLastRequestData()`/`getLastResponseXml()` metódusokat (amik ezt visszaadnák) egyetlen `app/`-beli hívás sem használja (grep nulla találat).
- Kivétel-üzenetek: a vendor `BaseExceptionResponse::getResultMessage()` (`vendor/.../BaseExceptionResponse.php:20-33`) kizárólag a NAV VÁLASZÁBÓL (`result.message`/`result.errorCode`) épül — ez NAV-oldali szöveg, nem a mi kérésünk tartalma, tehát nem tartalmazhat technikai felhasználó jelszót. A `HttpResponseError` (`vendor/.../HttpResponseError.php`) a nyers HTTP-választ ágyazza az üzenetbe — szintén NAV válasza, nem a kérésünk.
- A jelszó maga soha nem megy ki nyers formában a NAV felé sem — a `passwordHash` SHA-512 hash (`BaseRequestXml.php:88`), a `requestSignature` egy SHA3-512 hash (kérés-azonosító + időbélyeg + aláíró kulcs összefűzéséből, `BaseRequestXml.php:119-147`) — még lehallgatás esetén sem a nyers titok utazik a drótion.
- **Óvatossági megjegyzés (nem hiba, hanem guardrail a 4b javaslathoz):** ha egy jövőbeli implementáció a válasz-XML tárolásának javítására a `Reporter::getLastRequestData()`-t használná (ez lenne a logikus API a "valódi request/response" eléréséhez), az **a TELJES kérést adja vissza, benne a hitelesítő blokkal** — ezt tilos közvetlenül DB-be/logba írni maszkolás nélkül. Ezt a 4a pontban explicit kiemelem.

**Összegzés:** jelenleg nincs élesben kockázatos titok-szivárgás a NAV-láncban. A fő kockázat NEM biztonsági, hanem **megbízhatósági/megfelelőségi**: a `queryTransactionStatus` hiánya miatt egy NAV által ténylegesen elutasított számla is "Sent" (rendben) állapotban maradhat a rendszerben — ld. kiemelve a chat-összefoglalóban is.

## 4. Hiányelemzés és javaslat

Egy hibás beküldés utólagos rekonstruálásához ma rendelkezésre áll: ki (implicit — a job mindig automatikus, nincs "ki indította" mező), mikor (`created_at`), melyik számla (`invoice_id`), milyen kérés-adattal (`request_xml`, csak sikeres/hibás XML-építés esetén), mi volt a hiba szövege (`error_message`). **Hiányzik:** melyik NAV-környezetbe (test/production) ment, melyik `operation` (CREATE/MODIFY/STORNO) volt, mit válaszolt ténylegesen a NAV (csak a transactionId van, a tényleges elfogadás/elutasítás nincs lekérdezve), és semmilyen felület nem mutatja meg mindezt sem a számla oldalán, sem összesítve.

### a) Adatmodell

A `nav_submission_logs` tábla **bővítendő**, nem kiváltandó — jó alap, csak hiányos:

```
nav_submission_logs
  id                 (megvan)
  invoice_id          FK, cascadeOnDelete (megvan)
  company_id          FK  — ÚJ, denormalizált: gyors céges audit-lekérdezéshez indexszel,
                             anélkül hogy invoice_id-n át kelljen joinolni; queue-kontextusban
                             az invoice modellből olvasva töltendő (nem CurrentCompany-ból)
  operation           string/enum (CREATE|MODIFY|STORNO)  — ÚJ, a job MÁR ISMERI
                             ($this->operation), csak jelenleg nem kerül be a log-sorba
  environment         enum (test|production)               — ÚJ, a credential.environment-ből
  attempt_number      (megvan)
  transaction_id      string nullable — ÚJ, külön kereshető oszlop (ma csak a response_xml
                             string belsejében van elrejtve)
  processing_result    enum nullable (pending|done|nok)     — ÚJ, a queryTransactionStatus
                             eredménye tölti (4b pont); amíg nincs lekérdezve: 'pending'
  validation_messages   json nullable                       — ÚJ, a NAV businessValidation/
                             technicalValidation üzenetei (ERROR/WARN szint + kód + szöveg)
  request_xml          (megvan) — TOVÁBBRA IS csak az invoiceData XML, hitelesítő adat NÉLKÜL
  response_xml          text nullable — JAVÍTANDÓ SZEMANTIKA: a manageInvoice válasz
                             tényleges XML-je (Connector::getLastResponseXml()), NEM a
                             jelenlegi "transactionId: X" string
  status                (megvan: success|error) — a HTTP-hívás sikeressége, nem a NAV
                             végleges validációja (az a processing_result)
  error_message         (megvan)
  http_status_code      smallint nullable — ÚJ, a Connector debug-adatából
  created_at             (megvan, UPDATED_AT=null marad — a queryTransactionStatus
                             frissítés inkább ÚJ sorként írandó, ne UPDATE-elje a meglévőt,
                             hogy a "mikor mit tudtunk" history is megmaradjon)
```

Kapcsolat az `invoices` meglévő NAV-mezőivel: **`nav_status`/`nav_transaction_id`/`nav_sent_at` megmarad** mint a számla JELENLEGI, gyorsan olvasható állapota (ahogy ma is) — a `nav_submission_logs` a TELJES történet. Nincs migrálandó régi adat (a tábla üres/gyakorlatilag most kezdi élni magát), az új oszlopok `nullable`-ként adhatók hozzá törés nélkül.

**Nyers XML tárolás — 2 alternatíva:**

| | Alt A: DB `text` oszlop (JELENLEGI minta megtartása) | Alt B: fájl `storage/app/private/nav-logs/{company_id}/{invoice_id}/{attempt}-{request\|response}.xml`, DB-ben csak path |
|---|---|---|
| Előny | Egy tranzakcióban konzisztens az invoice-frissítéssel; nincs második rendszer (fájl+DB) szinkronban tartva; KKV-számlák XML mérete tipikusan kicsi | Kisebb DB-méret/backup; a PDF-tárolás mintáját követné |
| Hátrány | DB-méret hosszú távon nő | **Már ma is nyitott pont a PDF-eknél** (`docs/progress.md` #7: külső backup még nincs véglegesítve) — egy második fájlrendszeres, külön backupolandó adatkör bevezetése további üzemeltetési terhet adna ugyanahhoz a problémához, mielőtt a meglévőt megoldottuk |

**Javaslat: Alt A (DB oszlop)** — a jelenlegi minta helyes, csak a tartalma pontosítandó (ld. fent, `response_xml` valódi NAV-válasz legyen). Maszkolás: a `request_xml`/`response_xml` egyikébe SEM kerülhet a hitelesítő envelope (`getLastRequestData()` teljes kérése) — kizárólag az invoice-adat XML és a NAV üzleti válasza mentendő, ahogy ma is. Megőrzési idő: jogi/számviteli bizonyíték-jellegű adat (a NAV-beküldés maga adóhatósági adatszolgáltatás) — automatikus törlést NEM javaslok, a magyar számviteli elévülési idővel (jellemzően 8 év) összhangban dokumentálandó retenciós szabály, hasonlóan a PDF-archívumhoz.

### b) Írási pontok

1. **`SendInvoiceToNavJob::handle()`** (`app/Jobs/SendInvoiceToNavJob.php`) — mindkét `NavSubmissionLog::create()` hívás (sor 92, 107) bővítendő `company_id` (`$invoice->company_id`), `operation` (`$this->operation`), `environment` (`$credential->environment`), `transaction_id` mezőkkel. Company_id-t itt is a **már betöltött `$invoice` modellből** kell venni, nem `CurrentCompany`-ból (a fájl már most is ezt a mintát követi a modul-gate-nél, sor 38).
2. **Új job vagy a meglévő kiterjesztése a `queryTransactionStatus` lekérdezésére.** Javaslat: külön `NavQueryTransactionStatusJob` (`ShouldQueue`, `delay(now()->addSeconds(90))`-szal dispatch-elve a sikeres `manageInvoice()` UTÁN, mert a NAV aszinkron dolgozza fel) — bemenete `nav_submission_log_id` + `transaction_id`, `Reporter::queryTransactionStatus()`-t hívja, és a `processing_result`/`validation_messages` mezőkkel ÚJ log-sort ír (attempt-history megőrzése) vagy a meglévőt frissíti (döntés a 4a "UPDATED_AT" megjegyzés szerint). Itt is `company_id`-t explicit a job konstruktorába kell adni — NEM `CurrentCompany`-ra hagyatkozva.
3. **`Log::` bővítés:** a happy-path (`sor ~92-104`) és az error-path (`sor ~107-116`) jelenleg NEM ír a Laravel log-csatornára — érdemes ide is `Log::info`/`Log::error` hívást tenni (context: `invoice_id`, `company_id`, `attempt_number`, `transaction_id`/`error_message` — SOHA hitelesítő adatot), hogy a `storage/logs/laravel.log`-ból is kereshető legyen üzemeltetői incidens esetén, ne csak DB-ből.

### c) Megjelenítés

**Jogosultság:** a `nav.view_log` kulcs **MÁR LÉTEZIK** és regisztrálva van (`NavModule::permissions()`, `app/Modules/Definitions/NavModule.php:19`) — mindkét feloldási úton (Gate::before + PermissionChecker::effectivePermissionKeys()) automatikusan érvényesül modul-kulcsként, **nincs teendő az RBAC-bekötéshez**, csak a controller/route hiányzik.

**API-végpontok (javasolt, a `CompanyNavCredentialController` mintáját követve):**
- `GET /api/invoices/{invoice}/nav-submissions` — egy adott számla összes beküldési kísérlete, `assertBelongsToCurrentCompany()` a route-model-bindingre (a projekt kötelező mintája), jog: `invoice.view` VAGY `nav.view_log`.
- `GET /api/nav-submissions` — önálló, cégen belüli lista, szűrők: `status`, `date_from`/`date_to`, `only_errors` — jog: `nav.view_log`, `module:nav` middleware (a `CompanyNavCredentialController` route-csoportja mintájára, `routes/api.php:95`).

**Frontend:**
- `InvoiceDetailPage.jsx` — "NAV beküldési előzmények" panel (a meglévő `.alert-info` / `StornoNoticeLink` vizuális minta szerint), kísérletenként: dátum, `attempt_number`, státusz-badge, `transaction_id`, hibaüzenet. **Ma ezen az oldalon NAV-ról semmi nem látszik** — ez a legnagyobb azonnali UX-hiány, egyetlen NAV-hibás számlánál sem tudja a felhasználó a felületen megnézni, miért.
- Önálló lista oldal, pl. `/settings/nav-submissions` (a `ModulesPage`/`CompanyPage` NAV-szekció mellé, `settingsRoute()`-hoz hasonló elven), `nav.view_log` gate a sidebaron — szűrhető, hasznos tömeges áttekintéshez (hány hibás beküldés van most).

### d) Fejlesztési sorrend

1. **Migráció + modell bővítés** — `nav_submission_logs` új oszlopai (`company_id`, `operation`, `environment`, `transaction_id`, `processing_result`, `validation_messages`, `http_status_code`), `NavSubmissionLog.php` fillable/cast bővítés, `SendInvoiceToNavJob.php` a mezők kitöltésére. Érintett: 1 migráció, `NavSubmissionLog.php`, `SendInvoiceToNavJob.php`, `SendInvoiceToNavJobTest.php` kiegészítés.
2. **`queryTransactionStatus` polling** — új `NavQueryTransactionStatusJob`, dispatch a sikeres `manageInvoice()` után, `NavReporterFactory`/`Reporter` újrahasznosítva. Érintett: 1 új job, esetleg `NavReporterFactory` kiegészítés, új teszt-osztály.
3. **API réteg** — `NavSubmissionLogController` (index invoice-onként + saját lista), route-ok (`routes/api.php`), `NavSubmissionLogResource`, feature tesztek (jog mindkét irányban — Gate::before ÉS effectivePermissionKeys —, cross-company 404, `only_errors` szűrő). Érintett: 1 controller, 1 resource, route-fájl, teszt-osztály.
4. **Frontend — invoice-szintű panel** — `InvoiceDetailPage.jsx` bővítés, `frontend/src/api/navSubmissions.js` (list-hívás). Kézi böngészős teszt (a projekt konvenciója szerint, Sanctum SPA-auth miatt nem automatizálható).
5. **Frontend — önálló lista oldal** — `NavSubmissionsPage.jsx`, sidebar-bejegyzés (`Layout.jsx`), route (`App.jsx`), szűrő UI (a `SalesGroupPage`/`JobPositionPage` lista-minta újrahasznosításával).

## 1. fázis — elvégezve, 2. fázisra nyitva

Az 1. fázis (séma-bővítés + írási pontok, `2026_07_20_000001_add_submission_details_to_nav_submission_logs_table.php`) lezárva. Két pont maradt a 2. fázisra (`queryTransactionStatus` polling), mert azok döntése a polling-job tényleges viselkedésétől függ:

- **A `status` check constraint jelenleg csak `success`/`error`.** A `queryTransactionStatus` visszajelzés NAV-oldali feldolgozási állapota (pl. "feldolgozás alatt", NAV `INPROGRESS`) nem feleltethető meg egyik jelenlegi értéknek sem. A constraint bővítése (vagy a `processing_result` mező önálló, `status`-tól független kezelése) a 2. fázis migrációjának feladata — el kell dönteni, hogy a `status` oszlop a HTTP-hívás sikerességét jelöli-e továbbra is (ahogy ma), vagy bővül egy harmadik értékkel.
- **Az `attempt_number` és a `(invoice_id, attempt_number)` index szemantikája elmosódik, ha a polling-sorok ugyanabba a táblába kerülnek.** Ma minden sor egy tényleges `manageInvoice` beküldési kísérlet, sorszámozva. Ha a `queryTransactionStatus` lekérdezések is új sorként íródnak (nem UPDATE-elik a meglévőt), a 2. fázisban el kell dönteni: (a) a lekérdezés kap-e saját, `operation`-önkénti számlálót (pl. `attempt_number` csak a `manageInvoice` sorokon belül sorszámoz, a `queryTransactionStatus` sorok külön logikával), vagy (b) a `queryTransactionStatus` egyáltalán nem kap `attempt_number`-t (nullable marad rá), és a hozzá tartozó `manageInvoice` sorral `transaction_id`-n át korrelál, nem az attempt-sorszámon át.

## 2. fázis — elvégezve, 3. fázisra nyitva

A 2. fázis (`queryTransactionStatus` státusz-lekérdezés — a tényleges NAV-verdikt megszerzése) lezárva. A két nyitva hagyott 1. fázis-döntés így oldódott meg:

- **`status` check constraint változatlan maradt (NEM bővült).** A `status` oszlop szigorúan a HTTP-HÍVÁS kimenetele (success/error) marad, nem a NAV verdiktje — ezt egy külön, már 1. fázisban létező oszlop, a `processing_result` hordozza (NAV `invoiceStatus` érték: `RECEIVED`/`PROCESSING`/`SAVED`/`DONE`/`ABORTED`, a vendor lib `xsd/invoiceApi.xsd`-jéből, NEM találgatva). Egy "még feldolgozás alatt" válasz tehát `status=success` + `processing_result=PROCESSING`. Ez már az 1. fázis jelentésében felvetett (a) alternatíva volt — a döntés mellette esett.
- **`attempt_number` a `queryTransactionStatus` sorokon KÜLÖN, `operation`-önkénti számlálón fut** — `NavSubmissionLog::where('invoice_id', ...)->where('operation', 'queryTransactionStatus')->count() + 1`, nem keveredik a `manageInvoice` attempt-sorozatával (tesztelve: `NavTransactionStatusCheckerTest::test_attempt_number_runs_on_its_own_counter_separate_from_manage_invoice`).

**Váratlan, hasznos infó a NAV enumból (nem találgatva, a vendor lib `xsd/invoiceApi.xsd`/`common.xsd`-jéből):** a NAV `invoiceStatus` értékei ténylegesen `RECEIVED | PROCESSING | SAVED | DONE | ABORTED` — **nincs "FINISHED" érték** (ez a feladat-specifikáció feltételezése volt, de a valós XSD-ben `DONE` a végleges "elfogadva" jelentésű érték). A validációs üzenetek (`technicalValidationMessages`/`businessValidationMessages`) mindegyike `validationResultCode` (`ERROR`/`WARN`/`INFO`), opcionális `validationErrorCode` és `message` hármast hordoz.

**`invoices.nav_status` bővítve** (`2026_07_20_000003_extend_nav_status_enum_on_invoices_table.php`) 3 új végállapottal: `confirmed_with_warnings`, `rejected`, `needs_attention` — a meglévő `confirmed` érték (ami az 1. fázis auditjában derült ki, hogy már létezett a DB check constraintben és a PHP enumban is, de sehol nem lett ténylegesen beállítva) most az "elfogadva, nincs figyelmeztetés" végállapotot kapta. Verdikt-leképezés (`NavTransactionStatusChecker::check()`): `ABORTED` VAGY van `ERROR` súlyosságú üzenet → `Rejected`; `DONE` és van `WARN` (de nincs `ERROR`) → `ConfirmedWithWarnings`; `DONE` és nincs egyik sem → `Confirmed`; `RECEIVED`/`PROCESSING`/`SAVED` → a számla állapota változatlan marad (nincs végleges verdikt). Új index: `invoices(nav_status, nav_sent_at)` — ez hordozza a "NAV-verdiktre váró" lekérdezést.

**Biztonsági döntés a `request_xml`-ről (`queryTransactionStatus` sorokon NULL):** a `manageInvoice`-tól eltérően a `queryTransactionStatus` kérésnek nincs elkülöníthető, hitelesítő adat nélküli "üzleti tartalom" része — a teljes kérés-test lényegében a hitelesítő envelope (`login`/`passwordHash`/`requestSignature`) + a `transactionId`. A vendor lib egyetlen módja a ténylegesen elküldött kérés eléréséhez (`Reporter::getLastRequestData()`/`Connector::getLastRequestData()`) a TELJES, hitelesítő adatot tartalmazó kérést adná vissza — ezt a projekt sehol nem hívja. A `request_xml` ezért ezeknél a soroknál szándékosan `NULL` marad; a `transaction_id` (valódi, önálló oszlop) már úgyis rögzíti, mit kérdeztünk. A `response_xml`-be a NAV tényleges válasza kerül (nem tartalmaz hitelesítő adatot, biztonságos).

**Két mechanizmus, egy közös service-osztály (`NavTransactionStatusChecker`), a logika nem duplázódik:**
1. **`nav:check-submission-status` — az 5 percenként futó ÜTEMEZETT parancs, a garancia.** Az `Invoice::scopeAwaitingNavVerification()` (nav_status=sent ÉS van transaction_id) alapján gyűjti a függő beküldéseket, `company_id` szerint csoportosítva dolgozza fel — cégenként EXPLICIT hitelesítő-betöltéssel (`$company->navCredentials()->where(...)`), SOHA nem a `CurrentCompany` singletonra hagyatkozva (a scheduler-kontextusban az mindig `null`). Egy cég feldolgozási hibája `try/catch`-csel elkapva, naplózva, a többi cég feldolgozása folytatódik. `--invoice=` opcióval egyetlen számla manuálisan is ellenőrizhető, a 24 órás határidőt megkerülve (hibakereséshez).
2. **`CheckNavTransactionStatusJob` — a `SendInvoiceToNavJob` sikeres ága dobja el 60 mp késleltetéssel, a kényelem.** Egyszeri próbálkozás (`$tries = 1`), nem ütemezi újra magát — ha elhasal, az 5 perces ütemezett futás úgyis pótolja.

**24 órás feladási szabály:** ha egy beküldés `nav_sent_at`-ja 24 óránál régebbi és még mindig nincs végleges eredmény, a `NavTransactionStatusChecker::abandon()` — NAV-hívás NÉLKÜL — `nav_status=needs_attention`-re állítja a számlát és `Log::warning`-ot ír. A `nav_sent_at` (nem egy külön "első naplózás" időbélyeg) bizonyult a helyes referenciapontnak: ez már az 1. fázisban is pontosan azt a pillanatot rögzíti, amikor az adott `transaction_id`-hoz tartozó beküldés elsőként sikeresen elment.

**Egy tesztelés közben talált, ténylegesen javított hiba (nem éles kód, a tesztek saját fixture-hibája):** a többcéges parancs-tesztek (`CheckNavSubmissionStatusesTest`) elsőre tévesen buktak, mert a teszt-fixture-ök felállítása a `CurrentCompany` singletont az UTOLJÁRA létrehozott cégen hagyta — és mivel a `CompanyNavCredential` is `BelongsToCompany`-t használ, ez a korábbi cégek hitelesítő-lekérdezését tévesen kiszűrte (a global scope csak akkor inaktív, ha `CurrentCompany::id()` ténylegesen `null`, l. `BelongsToCompany::bootBelongsToCompany()`). Ez pontosan az a hibaosztály, amit a parancsnak magának el kell kerülnie éles környezetben (ahol a singleton mindig `null`) — a teszteket `app(CurrentCompany::class)->clear()`-ral javítottuk a `$this->artisan(...)` hívások előtt, hogy hitelesen szimulálják a valódi scheduler-kontextust.

`NavTransactionStatusCheckerTest` (8 teszt) + `CheckNavSubmissionStatusesTest` (5 teszt) + `SendInvoiceToNavJobTest` +1 új teszt (`CheckNavTransactionStatusJob` 60s késleltetéssel eldobva sikeres beküldéskor, `Queue::fake()`-kel ellenőrizve). **409/409 zöld sail-ként.**

NYITOTT (3. fázis — API + frontend, MÉG NEM kezdődött el):
- `NavSubmissionLogController` (API végpontok: invoice-onkénti előzmény-lista + önálló, szűrhető lista) — a `nav.view_log` jogkulcs már létezik és mindkét jog-feloldási úton (Gate::before + effectivePermissionKeys) automatikusan érvényesül, nincs teendő az RBAC-bekötéshez.
- Frontend: `InvoiceDetailPage.jsx` "NAV beküldési előzmények" panel — jelenleg a számla részletező oldalán SEMMI nem látszik a NAV-állapotról (a `nav_status` mező ma is csak a Dashboard összesítőjében jelenik meg, invoice-szinten sehol).
- Frontend: önálló NAV-beküldések lista oldal a NAV modulban, szűrhető állapotra/dátumra/csak hibásakra.
- A `docs/wiki/` NAV-fejezete frissítendő a queryTransactionStatus-mechanizmussal (jelenleg nem tér ki rá).
