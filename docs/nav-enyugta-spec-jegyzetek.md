# NAV nyugtaadat-szolgáltatás (eNyugta) — gépi interfész felderítő jegyzet

> **Státusz: READ-ONLY felderítés.** Ez a dokumentum kizárólag a NAV publikus
> `eRECEIPT` repójában található specifikáció és a jelenlegi kódbázis
> összevetése — nem tartalmaz implementációs döntést, migrációt vagy kódot.
> Ahol a forrás nem ad választ, az explicit jelölve: **„a spec nem rendelkezik
> róla”**.

---

## 1. Forrás és verzió

- **Repo:** https://github.com/nav-gov-hu/eRECEIPT
- **Letöltött commit:** `a372dbe52e16acb7bc6dd7f2c4b74e820acaf0bb` (repo-oldali commit dátum: 2026-07-10)
- **Letöltés ideje:** 2026-07-20, shallow clone (`--depth 1`) a `/tmp/eRECEIPT` ideiglenes könyvtárba, a felderítés végén törölve.
- **Fő forrásdokumentum:** `docs/specification/NAV_Nyugta_adatszolgaltatas_IF_specifikacio_v1.0.pdf`
  — *„NAV Nyugta-adatszolgáltatás — Számítógéppel kiállított nyugta-adatszolgáltatási
  REST API interfészleírás és fejlesztői dokumentáció”*, **Verzió 1.0**,
  dátum: **2026.07.01.** A dokumentum-történet (2. oldal) szerint ez az
  **„Első publikált változat”** — nincs korábbi revízió, amihez viszonyítani lehetne.
- **XSD séma:** `xsd/1.0/receipt_datareport/receipt-if-schema-v1.0.xsd` — a séma
  a `common`, `customer`, `type`, `authservice`, `paging`, `service` névtereket
  **külső URL-ről** (`raw.githubusercontent.com/nav-gov-hu/Common`, tag
  `common-2.0.0-rc.2`) importálja `xs:import schemaLocation`-nel, tehát a
  bundled fájl önmagában nem teljes — a típusdefiníciók egy része más repóban van.
- A spec XML-forrásfájl neve (a doksiban hivatkozva): `receipt.xsd` +
  `common.xsd` (1.5.1 fejezet, 9. oldal) — a repóban ténylegesen kiajánlott fájl
  neve `receipt-if-schema-v1.0.xsd`, tartalmilag ennek felel meg.

**Kizárt, nem releváns anyag (azonosítva, tudatosan kihagyva):** a repo döntő
része (`docs/specification/ePenztargep_fejlesztoi_dokumentacio_v1.*.pdf`,
`e-Cash_Register_Developer_Documentation_English*.pdf`, `xsd/1.0/eReceipt`,
`xsd/1.0/eCustomerApp`, `xsd/1.0/eDocumentStore`, `xsd/1.1/*`, valamint a
`docs/specification/changelog/eNyugta_xsd_github_valtozasok_1.1*` fájlok) az
**e-pénztárgép** (fizikai, típusengedélyezett hardvereszköz) oldali
gépi interfészt írja le — ez a jogszabályi keret szerint kifejezetten **nem**
ez a projekt (a törvény szerinti „e-nyugtát” kizárólag e-pénztárgéppel lehet
kiállítani), ezért a jegyzet nem dolgozza fel részletesen.

---

## 2. Hitelesítés

**Technikai felhasználó — külön regisztráció kell.** A spec (Bevezetés,
4–5. oldal) szerint az adatszolgáltatásra kötelezett adózónak **technikai
felhasználót kell létrehoznia a NAV Felhasználókezelő rendszerben**, és ehhez
kell aláírókulcsot + cserekulcsot generáltatnia. A dokumentum **nem használja
a „KOBAK” kifejezést egyetlen alkalommal sem**, és **nem mondja ki explicit
módon**, hogy ez ugyanaz-e a felhasználókezelő rendszer/portál, mint amit az
Online Számla 3.0 technikai felhasználóinál használunk, sem azt, hogy egy már
létező Online Számla technikai user újrahasználható-e. Amit a spec kimond: „Az
adózó tetszőlegesen megválaszthatja, hogy adatszolgáltatásai teljesítéséhez
hány technikai felhasználót igényel” (5. oldal) — ez megengedi, de nem írja
elő a szétválasztást. → **A spec nem rendelkezik róla explicit módon; tisztázandó
kérdés.**

**Szükséges mezők** (`/auth/token`, `AuthTokenRequest`, 11–12. oldal):

| Mező | Kötelező | Tartalom |
|---|---|---|
| `auth/login` | igen | technikai felhasználó login neve |
| `auth/passwordHash` | igen | jelszó **SHA3-512** hash-e |
| `auth/taxNumber` | igen | adózó adószámának **8 jegyű törzsszáma**, pattern `[0-9]{8}` |
| `auth/predecessorTaxNumber` | **nem** | jogelőd adószáma (opcionális) |
| `auth/requestSignature` | **nem** | a kérés aláírásának SHA3-512 hash-e — **opcionális mező** |
| `requestVersion`, `headerVersion` | nem | verziójelzők |

**Hash-algoritmus: SHA3-512, nem SHA-512.** A „Nyugta kiállító szoftverre
vonatkozó technikai követelmények” szakasz (5. oldal) kifejezetten
`SHA3-512 encode (RFC6234)` algoritmust ír elő. **Megjegyzendő anomália:** az
RFC 6234 ténylegesen az SHA-2 családot (SHA-1/224/256/384/512) definiálja, nem
az SHA-3-at (azt az FIPS 202 / RFC 8702 írja le) — a spec tehát önellentmondó
RFC-hivatkozást tartalmaz. Ez implementációs kockázat: az algoritmus neve
(SHA3-512) egyértelmű, de az idézett szabvány nem hozzá tartozik — valódi NAV
sandbox-tesztelésig nem dönthető el biztosan, hogy a NAV oldal ténylegesen
Keccak/SHA-3-512-t vár-e (PHP `hash('sha3-512', …)`, natívan elérhető PHP
7.1+ óta, tehát nem igényel új vendor-függőséget).

**A `requestSignature` pontos képzési szabálya nincs dokumentálva.** A spec
csak annyit mond (5. oldal): „Az aláírókulcs az üzenetek aláírására szolgáló
requestSignature számításában játszik szerepet.” Ezzel szemben az Online
Számla 3.0-nál jól dokumentált a konkatenációs képlet
(`requestId + timestamp(rövidített) + signKey`, SHA-512). Az eNyugta spec-ben
**nincs ilyen képlet leírva**, és mivel a mező maga is opcionális
(„nem” kötelező az `AuthTokenRequest`-ben) → **a spec nem rendelkezik róla,
implementálás előtt tisztázandó, vagy egyelőre kihagyandó, ha nem kötelező.**

**Cserekulcs (exchange key) szerepe ellentmondásosnak tűnik.** A Bevezetés
(5. oldal) szerint „a cserekulcs az adatszolgáltatási token szerveroldali
elkódolásához és a kliensoldali dekódolásához szükséges” — ez az Online
Számla mintáját idézi (token titkosítás/dekódolás cserekulccsal). Ugyanakkor
az `AuthTokenResponse` mezőleírása (12. oldal) egy sima `token` (xs:string,
max. 600 karakter) + `validTo` párost ad vissza, **nincs benne dokumentálva
dekódolási lépés vagy kapcsolódó mező**. → **A spec nem rendelkezik
egyértelműen arról, hogy a cserekulcs ténylegesen szerepet játszik-e a
konkrét `/auth/token` válasz feldolgozásában, vagy csak általános
leírás-szintű megjegyzés.**

**Következtetés — bővíthető-e a meglévő `company_nav_credentials` tábla:**
A tábla jelenlegi mezői (`nav_login`, `nav_password`, `nav_signing_key`,
`nav_exchange_key`, `nav_tax_number`, mind `encrypted` cast) **név szerint 1:1
megfelelnek** az eNyugta interfész igényeinek (login, jelszó → passwordHash,
signing key → requestSignature, exchange key → a fent említett, tisztázatlan
szerepű token-kezelés, taxNumber). Sőt: a `nav_tax_number` mező már ma is a
**bare 8 jegyű törzsszámot** tárolja (l. `docs/progress.md` NAV 3. fázis
bejegyzése, `a785209` javítás) — ez pontosan megegyezik az eNyugta
`taxPayerId`/`auth:taxNumber` elvárt formátumával (`[0-9]{8}`), tehát ebben a
mezőben **nincs eltérés** a két interfész között.

Mivel azonban (a) a spec megengedi külön technikai user használatát
szolgáltatásonként, és (b) a hash-algoritmus (SHA3-512 vs Online Számla
SHA-512) és a base URL/protokoll teljesen különböznek — **architekturális
döntési pont, amit nem old fel ez a jegyzet:** vagy egy megkülönböztető oszlop
(pl. `service` enum: `online_szamla` | `enyugta`) kerül a meglévő táblába, vagy
külön `company_enyugta_credentials` tábla készül a `company_nav_credentials`
mintájára. Mindkettő architekturálisan indokolható; a végső döntést a
CLAUDE.md elve szerint a felhasználóra kell hagyni.

---

## 3. Endpointok és protokoll

- **Context root:** `/receipt-if` (1.5.1 fejezet, 9. oldal).
- **Konkrét host/base URL (teszt és éles) sehol nincs megadva a dokumentumban.**
  Grep-elve a teljes szöveget (URL-ek, „sandbox”, „teszt rendszer”, „környezet”
  kulcsszavakra) — a spec kizárólag XML-névtér URI-kat
  (`http://schemas.nav.gov.hu/NTCA/...`) és a `/receipt-if` context rootot
  tartalmazza, **tényleges hostname-et (pl. egy Online Számlánál megszokott
  `api-test.onlineszamla.nav.gov.hu`-hoz hasonlót) nem**. → **A spec nem
  rendelkezik róla.**
- **⚠️ KRITIKUS: rendelkezésre áll-e sandbox 2026. szeptember 1. előtt — a
  spec erre SEMMILYEN választ nem ad.** Nincs sandbox-URL, nincs
  regisztrációs/hozzáférés-igénylési folyamat leírása, nincs dátum a
  sandbox indulására. Ez a legnagyobb kockázat a projekt ütemezése
  szempontjából (l. 10. fejezet).
- **Protokoll:** REST, **XML** payload (nem JSON), HTTP POST, UTF-8 kódolás
  (`<?xml version="1.0" encoding="UTF-8"?>` minden példában). A válasz is
  mindig XML body-ban érkezik (1.5.1 fejezet).
- **HTTP-válaszkódok:** helyes kérésnél **mindig HTTP 200** — az üzleti
  siker/hiba a válasz XML `resultCode` mezőjében (SUCCESS/ERROR/WARN/INFO)
  jelenik meg, nem a HTTP státuszban (1.5.3 fejezet, 9. oldal). Ez megegyezik
  az Online Számla 3.0 mintájával.
- **Méretkorlát:** HTTP POST body max. **10 MB** operációnként (1.5.4, 10. oldal).
  Tömörítésről (pl. gzip) a spec **nem** szól.
- **Válaszidő/timeout:** jellemző válaszidő < 200 ms, szinkron hívás blokkoló
  timeout-ja **5000 ms** (1.5.5, 10. oldal).
- **Erőforrások (10 endpoint, mind a `/receipt-if` alatt, 1.5.2 fejezet):**
  `/auth/token`, `/receipt/list`, `/receipt/create`, `/receipt/detail`,
  `/receipt/modify`, `/receipt/invalidate`, `/issuing-software/create`,
  `/issuing-software/list`, `/vat-category/list`, `/currency/list`.

---

## 4. Üzenetstruktúra — napi összesítő (`/receipt/create`)

Forrás: 2.1.3 fejezet (17–19. oldal) + XSD `CreateReceiptRequestType`
(`receipt-if-schema-v1.0.xsd:295-370`). Az XSD-ben ellenőrzött **elemsorrend**
(`xs:sequence`, kötelező betartani, mint az Online Számlánál a `LineType`-nál):

```
taxPayerId → issuingSoftware → applicableDate → serialNumber → currency →
exchangeRate → vatCategoryItems → total → numberOfSaleDocument →
numberOfModifyingDocument
```

| Elem | Típus/facet | Kötelező | Megjegyzés |
|---|---|---|---|
| `taxPayerId` | `ntcaCustomer:TaxpayerIdType`, `[0-9]{8}` | igen | bare 8 jegyű törzsszám |
| `issuingSoftware/name` | `ntcaString:AtomicStringType100`, max. 100 kar. | igen | **előzetesen regisztrálandó** a `/issuing-software/create` végponton, l. lent |
| `applicableDate` | `ntcaType:GenericDateType`, `\d{4}-\d{2}-\d{2}` | igen | tárgynap — **nem lehet jövőbeli dátum** |
| `serialNumber` | `ReceiptSerialNumberType`, max. 50 kar. | igen | az adatszolgáltatásban szereplő **kezdő** nyugtaszám |
| `currency` | `ntcaCustomer:CurrencyType`, `[A-Z]{3}` (ISO 4217) | igen | csak a `/currency/list` végponton kiajánlott kód fogadható el |
| `exchangeRate` | `ExchangeRateType`, `nillable="true"`, max 4 tizedesjegy, `totalDigits=9` | igen | 1 egységre vonatkozó árfolyam; **HUF esetén nullable vagy fix 1** |
| `vatCategoryItems/vatCategory/vat` | string, max. 50 kar. | igen | ÁFA-kategória **neve** — csak a `/vat-category/list` katalógusban szereplő érték fogadható el |
| `vatCategoryItems/vatCategory/saleDocument` | `PositiveFixedDecimal112Type`, 0–999 999 999.99, 2 tizedesjegy | igen | csak **pozitív vagy 0** — a nyugták bruttó összértéke |
| `vatCategoryItems/vatCategory/modifyingDocument` | `FixedDecimal112Type`, ±999 999 999.99, 2 tizedesjegy | igen | **negatív is lehet** — módosító/érvénytelenítő bizonylatok bruttó összege |
| `total` | `FixedDecimal212Type`, 2 tizedesjegy | igen | összesített bruttó érték, **negatív is lehet** |
| `numberOfSaleDocument` | `CountType`, 0–99 | igen | nyugták darabszáma |
| `numberOfModifyingDocument` | `CountType`, 0–99 | igen | módosító/érvénytelenítő bizonylatok darabszáma |

**Kerekítés/pénznem:** minden összeg max. 2 tizedesjegy, kivéve az
`exchangeRate` (4 tizedesjegy). Nincs forint-normalizálási mező a
`create` kérésben (ellentétben a `/receipt/list` és `/receipt/detail`
válaszokkal, ahol van `totalAmountInForint` / `total.../totalAmountInForint`,
`ForintValueType`, 0 tizedesjegy). A `create` kérésnél a NAV szerveroldalon
számolja ki a forintértéket az `exchangeRate` alapján.

**Üzleti szabály (kötelező, 19. oldal, 10. pont):** a két darabszám mezőből
**legalább az egyiknek 0-tól eltérő értéket kell tartalmaznia** — nem
küldhető be teljesen üres napi jelentés ezen a csatornán (l. 7. fejezet is).

**Szoftver-azonosító: van, de MÁS mechanizmusú, mint az Online Számlánál.**
Az Online Számla 3.0-nál a szoftver-azonosítók (`softwareId`,
`softwareName`, stb.) **statikus konfigurációs adatok**, amiket minden
`manageInvoice` hívás fejlécében elküldünk (l. `config/nav.php` `software`
tömb, `NavReporterFactory`). Az eNyugta interfésznél ezzel szemben a
`issuing-software/name` egy **előzetesen, külön API-hívással
(`/issuing-software/create`) regisztrálandó** szabad szöveges név (2.1.3.1,
19. oldal, 2. pont: „Adatszolgáltatás küldését megelőzően az itt megadott
szoftver nevet rögzíteni kell a `/issuing-software/create` operáció
segítségével”), és utána minden `/receipt/create` hívásban ugyanezt a nevet
kell hivatkozni. Ha a hivatkozott név nem létezik, `EPGP0002
NEM_LETEZO_SZOFTVER` hibát ad vissza a rendszer. → **Ez egy új, a mai
kódbázisban nem létező onboarding-lépés**, nem egyszerű konfig-érték.

**A `CreateReceiptResponse` nem ad vissza adatszolgáltatás-azonosítót** —
csak a `BaseResponseType`-ból áll (`context/requestId`, `resultCode`,
`message`), semmi egyéb (2.1.3.2, 19. oldal). Az azonosítót (`id`, formátum
`[0-9]{8}_[0-9]{8}_[0-9]+`) csak a **`/receipt/list`** válaszban lehet
visszakeresni — l. 5. fejezet.

---

## 5. Idempotencia és nyugtázás

- **Tranzakció-azonosító a válaszban NINCS** a `CreateReceiptResponse`-ban
  (l. fent) — csak a kérésben küldött `context/requestId` (kliens-generált
  UUID) kerül visszaküldésre nyugtázásképp. A tényleges NAV-oldali
  rekordazonosítót (`id`, pattern `[0-9]{8}_[0-9]{8}_[0-9]+` — feltehetően
  `{adózó adószám}_{szoftver-azonosító vagy hasonló}_{sorszám}` szerkezetű,
  de ezt a spec nem magyarázza el explicit) csak a `/receipt/list` /
  `/receipt/detail` lekérdezéssel lehet visszaszerezni.
- **Feldolgozás: teljesen szinkron, nincs külön státusz-lekérdezési
  mechanizmus.** Ellentétben az Online Számla 3.0 kétlépcsős
  `manageInvoice` → `queryTransactionStatus` folyamatával (amit ez a
  kódbázis már implementált, l. `NavTransactionStatusChecker`), az eNyugta
  `/receipt/create` válasza **azonnal, szinkron módon** SUCCESS vagy ERROR
  (1.1 fejezet, 6. oldal: „a szerveroldali feldolgozás… szinkron módon
  történik”). **Nincs analóg `queryTransactionStatus`-jellegű endpoint** a
  10 felsorolt erőforrás között — a `/receipt/detail` egy már RECORDED
  rekord adatainak lekérdezésére szolgál, nem egy függőben lévő beküldés
  állapotára.
- **requestId egyediség adózónként, nem globálisan:** „A requestId-nak az
  adott adózó vonatkozásában kérésenként egyedinek kell lennie” (1.3, 7. oldal).
- **Duplikált beküldés viselkedése: a spec nem ír le explicit
  deduplikációs szabályt** a `/receipt/create`-re nézve (pl. hogy egy
  ugyanolyan `applicableDate`+`serialNumber` párral kétszer beküldött
  rekord hibát adna-e, vagy két külön RECORDED sort hozna létre). →
  **A spec nem rendelkezik róla explicit módon** — csak a `requestId`
  technikai egyediségét írja elő, az üzleti tartalom (tárgynap)
  duplikációjáról nem szól.
- **Token-érvényesség:** 5 perc a kiállítástól számítva (jelenleg; a doksi
  szerint ez „később változhat”) — minden adatszolgáltatás előtt újra kell
  igényelni, de egy tokennel **több nyugta-adatszolgáltatás is beküldhető**
  a lejáratig (1.1, 6. oldal).

---

## 6. Módosítás / sztornó

A mechanizmus **nem diff-alapú javítás, hanem invalidate + újrarögzítés**
(2.1.5, 24–26. oldal):

1. Egy már RECORDED rekord **csak akkor módosítható**, ha előtte sikeres
   `/receipt/invalidate` hívással INVALIDATED státuszba került (2.1.5.1
   Leírás, 26. oldal: „Módosítás csak 'INVALIDATED' státuszban lévő
   adatszolgáltatások esetén lehetséges. Módosítás előtt sikeres
   InvalidateReceiptRequest hívás szükséges”).
2. A `/receipt/modify` sikeres hívása **új rekordot hoz létre RECORDED
   státusszal** — nem az eredeti sort írja felül. „Egy érvénytelenített
   rekord többször újra rögzíthető a módosítási művelet segítségével”
   (26. oldal) — vagyis egy adott napi rekord invalidate→modify ciklusa
   akárhányszor megismételhető.
3. A `ModifyReceiptRequest` mezői **azonosak** a `CreateReceiptRequest`
   mezőivel, plusz a módosítandó rekord `id`-je — tehát egy módosításnál a
   **teljes napi összesítőt újra be kell küldeni**, nem csak a változó
   részt (nincs parciális update).
4. Az `InvalidateReceiptRequest` csak `id` + `taxPayerId` párost vár
   (2.1.6.1, 27. oldal) — maga az érvénytelenítés indoklás/ok nélküli.
5. **Időkorlát a javításra: a spec nem ad meg konkrét határidőt** (pl. hogy
   az eredeti 3 naptári napos beküldési határidőn belül vagy azon túl is
   javítható-e egy már RECORDED rekord). → **A spec nem rendelkezik róla.**

---

## 7. Nullás nap

**A spec explicit módon nem mondja ki**, hogy kötelező-e adatot szolgáltatni
olyan napról, amikor nem volt nyugta. Amit a spec ténylegesen tartalmaz,
csak közvetve releváns: a `/receipt/create` kérésben „a két darabszám mezőből
az egyiknek kötelezően 0-tól eltérő értéket kell tartalmaznia” (19. oldal,
10. pont) — ez azt **jelenti**, hogy egy `numberOfSaleDocument=0 AND
numberOfModifyingDocument=0` kérés a séma szintjén **el lenne utasítva**,
tehát egy technikailag „üres” napot **nem lehet** ezen a végponton
elküldeni. Ebből *következtethető* (de a spec nem mondja ki), hogy nullás
napról nem is kell/nem is lehet adatot szolgáltatni — de mivel ez
jogértelmezési kérdés is (Áfa tv. 257/G. §), és a spec maga nem tér ki rá
tételesen, **ez nem tekinthető megerősített ténynek** a dokumentum alapján.

---

## 8. Hibakódok

A teljes hibakódlista (3.2 fejezet, 34–35. oldal) — **mindössze 7 kód**,
mindegyik **blokkoló** jellegű (nincs a listában „figyelmeztetés” szintű,
nem-blokkoló kód, bár a `BaseResponseType.resultCode` enumban szerepel
WARN/INFO érték is, l. 1.4 fejezet):

| # | Hibakód | Hibaüzenet | Leírás |
|---|---|---|---|
| 1 | `EPGP0002` | `NEM_LETEZO_SZOFTVER` | Nem létező szoftver megnevezés |
| 2 | `EPGP0010` | `HIBAS_NYUGTAADAT_MENTES` | Hiba a nyugta-adatszolgáltatás mentése során, `{{exception}}` |
| 3 | `EPGP0011` | `NEM_LETEZO_NYUGTAADAT` | Nyugta-adatszolgáltatás nem létezik: `{{kapcsolodoAdatrogzitesAzonosito}}` |
| 4 | `EPGP0017` | `HIBAS_AFA_KATEGORIA` | Nem található ÁFA kategória a megadott azonosítóval |
| 5 | `EPGP0020` | `DUPLIKALT_AFA_KATEGORIA` | Adott ÁFA kategória többször szerepel az adatszolgáltatásban |
| 6 | `EPGP9000` | `INTERNAL_ERROR` | Jakarta adatvalidációs hiba (összegek/darabszámok ellenőrzésénél) |
| 7 | `EPGP9001` | `REQUEST_VALIDACIO_SIKERTELEN` | A kérés struktúrája nem felel meg a validációs szabályoknak |

Emellett **három hibatípus-struktúra** van definiálva (3.1 fejezet,
32–33. oldal): technikai (`TechnicalErrorResponse`), üzleti
(`BusinessErrorResponse` — ez tartalmazza a fenti `errorCode`-okat), és XSD
validációs (`InvalidRequestResponse` — mezőszintű `error/field` +
`error/error` párokkal).

**Rate limit / kvóta: a spec egyáltalán nem említi.** Sem a hibakódlistában
nincs erre utaló kód (pl. „too many requests”), sem szöveges említés nincs
róla máshol a dokumentumban. → **A spec nem rendelkezik róla.**

---

## 9. Adatmodell-hézag elemzés

| NAV-mező (create/napi összesítő) | Megvan-e ma a `receipts`/kapcsolódó táblákban | Forrás / hiányzó rész |
|---|---|---|
| `taxPayerId` (adózó adószám, 8 jegy) | **megvan** | `companies.tax_number` — bare törzsszám ugyanazzal a mintával kinyerhető, mint `NavXmlBuilder::addTaxNumberFields()` teszi |
| `issuingSoftware/name` | **részben** | a *név* forrása lehetne `config('nav.software.name')`, DE hiányzik: (a) az előzetes `/issuing-software/create` regisztráció API-hívása és annak nyoma, (b) állapot-tárolás arról, hogy a regisztráció megtörtént-e egy adott környezetben |
| `applicableDate` (tárgynap) | **megvan mint forrás, de nincs napi aggregátum** | `receipts.issue_date` egyenkénti bizonylatokon van — az eNyugta egy **ÚJ, napi szintű összesítő entitást** igényel (nincs ilyen tábla ma) |
| `serialNumber` (a nap kezdő sorszáma) | **származtatható** | `receipts.receipt_number` — a nap első nyugtájának száma; új lekérdezési logika kell |
| `currency` | **megvan** | `receipts.currency` |
| `exchangeRate` | **megvan (bizonylatonként)** | `receipts.exchange_rate` — validálandó, hogy egy napon belül minden nyugta ugyanazt az árfolyamot használja-e (a napi adatszolgáltatás egyetlen árfolyamot vár) |
| `vatCategoryItems/vat` (ÁFA-kategória NEVE) | **részben** | `vat_rates.name` vagy `vat_rates.nav_code` — **tisztázandó**, melyik felel meg szó szerint a NAV `/vat-category/list` katalógusának (a mai `nav_code` az Online Számla `AAM`/`TAM`-szerű kódjaira lett szabva, nem biztos, hogy egyezik az eNyugta kategórianevekkel) |
| `vatCategoryItems/saleDocument`, `/modifyingDocument` | **származtatható** | `receipt_items.gross_amount` GROUP BY `vat_rate_id`, státusz szerint (issued vs. storno) szétválasztva — a `ReportService` HUF-normalizáló/aggregáló mintájára |
| `total`, `numberOfSaleDocument`, `numberOfModifyingDocument` | **származtatható** | `SUM(receipts.gross_total)` / `COUNT(*)` a tárgynapra, `receipts.status` (`issued`/`storno`) szerint csoportosítva |
| napi adatszolgáltatás azonosítója (NAV `id`) + állapot (RECORDED/INVALIDATED) + kérés/válasz-napló | **nincs** | nincs analóg tábla a nyugta oldalon; az `invoices.nav_status`/`nav_transaction_id`/`nav_sent_at` + `nav_submission_logs` mintája adaptálható, de **új** tábla(k) kellenek |
| **telephely/üzlet-szintű azonosító** | **nincs — de a spec sem kéri** | a `/receipt-if` interfész kizárólag `taxPayerId` + `issuingSoftware/name` szinten azonosít; **a NAV séma egyáltalán nem tartalmaz site/premise-jellegű mezőt** (sem a PDF-ben, sem az XSD-ben — ellenőrizve `grep`-pel). **Ez tehát NEM hézag**: sem a NAV interfész, sem a jelenlegi rendszer nem ismeri ezt a fogalmat, a kettő konzisztens. |

---

## 10. Kockázatok és nyitott pontok

A 2026. szeptember 1-i határidőig (a mai naptól, 2026-07-20-tól számítva
**kb. 6 hét**) a legfontosabb, **blokkolhatja a fejlesztést** jellegű nyitott
pontok:

1. **⚠️ Sandbox-elérhetőség — nincs válasz a specifikációban.** Nincs
   sandbox-URL, nincs regisztrációs folyamat leírás, nincs dátum. Enélkül a
   teljes NAV-integráció **teszt nélkül, vakon** kellene, hogy elkészüljön —
   ugyanaz a mintázat, mint az Online Számla integrációnál kiderült
   (`INVALID_SECURITY_USER`, mezőformátum-hibák), csak ott már működő
   sandbox-hozzáférés mellett derültek ki a problémák. Itt még ez sincs
   megerősítve.
2. **A spec „1.0, első publikált változat”, 2026.07.01-i dátummal** — a
   README (a repo gyökerében, bár az az e-pénztárgép anyagra vonatkozik)
   explicit megjegyzi, hogy a dokumentáció munkaanyag jellegű és
   „a fejlesztői visszajelzések alapján még változhat”. A receipt-if spec
   maga nem tartalmaz ilyen figyelmeztetést, de mivel ez az első verzió,
   érdemi változás nem zárható ki.
3. **SHA3-512 + hibás RFC-hivatkozás (RFC6234)** — implementációs
   kockázat, amíg nincs valódi végpont, amin tesztelhető a hash-formátum.
4. **`requestSignature` képzési szabálya dokumentálatlan** — bár opcionális
   mezőnek van jelölve, tisztázandó, hogy gyakorlatban elvárja-e a NAV.
5. **Issuing-software regisztráció mint új, előfeltétel jellegű lépés** —
   ha ez elmarad vagy elgépelt a név, minden későbbi `/receipt/create`
   `EPGP0002 NEM_LETEZO_SZOFTVER` hibával bukik. Ez egy teljesen új
   hibaosztály, amit a jelenlegi NAV-integráció (Online Számla) nem ismer.
6. **Rate limit dokumentálatlan** — ismeretlen, hány kérés/mp engedélyezett,
   ami a napi összesítő-küldés ütemezésének tervezésekor releváns lehet
   sok céges multi-tenant környezetben.
7. **Módosítás időkorlátja dokumentálatlan** — nem tudni, meddig javítható
   visszamenőleg egy már beküldött nap.
8. **ÁFA-kategória-név megfeleltetés (`vat_rates` ↔ NAV `/vat-category/list`)
   nincs még ellenőrizve** — ez pontosan az a fajta hézag, ami az Online
   Számla `vatExemption` case-kódjainál is valós NAV-teszt-beküldésig rejtve
   maradt (l. `docs/progress.md` Nyitott pont #1).

---

## 11. Javasolt következő lépés

A felderítés alapján a **modul-katalógus regisztráció + credential-mező
tervezés (1. fázis, tisztán architekturális előkészítés)** minden további
tisztázás nélkül **elindulhat** — ez a lépés nem igényel valódi NAV-hívást,
és a meglévő `sales_group`/`assets`/`job_position` modulok bevezetési
mintája (scaffolding → séma → modell → API → frontend) közvetlenül
követhető. A `company_nav_credentials` mezőnevek és az eNyugta
hitelesítési igény közötti egyezés (2. fejezet) miatt a credential-tárolás
tervezése is alacsony kockázatú.

**Azonban a tényleges NAV-hívó logika (XML-builder + HTTP-reporter,
az Online Számla `NavXmlBuilder`/`NavReporterFactory` mintájára) megírása
előtt erősen javasolt előbb tisztázni — lehetőleg közvetlenül a NAV felé
(a repo README-je az `enyugta@nav.gov.hu` címet és a
https://github.com/nav-gov-hu/eRECEIPT/discussions fórumot ajánlja fel
erre) — legalább a következő négy, blokkoló jellegű kérdést:** (1) van-e és
mikor lesz elérhető sandbox-környezet és annak URL-je; (2) a technikai
felhasználó megosztható-e az Online Számlával, vagy külön regisztráció
szükséges; (3) a `requestSignature` pontos képzési szabálya, ha a NAV
gyakorlatban mégis megköveteli; (4) a `/vat-category/list` katalógus
tényleges tartalma, hogy a `vat_rates` táblával összevethető legyen. E négy
kérdés bármelyikének tisztázatlansága azt kockáztatja, hogy a fejlesztői idő
egy olyan implementációba megy, amit valós NAV-teszt-beküldéskor (a
korábbi Online Számla tapasztalat szerint, l. `a785209` commit) módosítani
kell — a 6 hetes határidő fényében ez az idő nem biztos, hogy rendelkezésre
áll egy második iterációra.
