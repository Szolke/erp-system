# ER-modell — Kisvállalati ERP

Státusz: tervezet (v1) — a Laravel migrations/modellek ez alapján készülnek.

## Tervezési alapelvek

1. **Multi-company elkülönítés**: minden tenant-specifikus táblában `company_id` (FK → `companies.id`, `restrict`).
   Minden lekérdezést a modell rétegben egy `BelongsToCompany` trait + globális Eloquent scope fog kötelezően
   szűrni az aktuális céghez (migrations után, a modell-fázisban kerül bevezetésre).
2. **RBAC company-szinten értelmezett**: egy felhasználó cégenként más csoportba (szerepkörbe) tartozhat, ezért a
   `groups` tábla is `company_id`-hoz kötött, nem globális.
3. **Jogosultság-feloldás sorrendje** (alkalmazás logika, nem DB-kényszer):
   `user_permission_overrides` (allow/deny, user+company+permission specifikus) →
   ha nincs override: a felhasználó cégen belüli csoportjainak `group_permissions` uniója →
   egyébként nincs jog.
4. **Pénznem**: minden bizonylat tárolja a kiállításkori MNB középárfolyamot (`exchange_rate`,
   `exchange_rate_date`), hogy utólag az árfolyam ne változzon a bizonylaton (számviteli/NAV elvárás).
   Az árfolyamok napi cache-elése az `exchange_rates` táblában.
5. **NAV / SimplePay aszinkron**: a számla/nyugta rekord tartalmaz állapotmezőket (`nav_status`,
   `nav_*`), a tényleges kommunikáció queue job-ban történik; minden NAV-beküldési kísérlet külön sorban
   naplózva (`nav_submission_logs`) — retry esetén is visszakövethető.
6. **Bizonylatok immutability**: kiállított számla/nyugta sorai és összegei a kiállítás után nem
   módosíthatók — soft delete sincs rajtuk, csak sztornó (önhivatkozó `storno_of_*_id` mezővel).
7. **Kulcsstratégia**: `bigIncrements` (Laravel default `id`), az üzleti azonosító (pl. számlaszám) külön,
   ember-olvasható mező (`invoice_number`), nem ütközik a technikai PK-val.
8. **Naming**: angol tábla-/mezőnevek (Laravel/Postgres konvenció), az üzleti jelentés magyarul ebben a
   dokumentumban és a kódkommentekben, ahol nem triviális.

---

## 1. Cégek és törzsadataik

### `companies`
| mező | típus | megjegyzés |
|---|---|---|
| id | bigIncrements | |
| name | string | cégnév |
| tax_number | string(11) | adószám, pl. `12345678-1-42` formátum |
| eu_tax_number | string nullable | közösségi adószám |
| registration_number | string | cégjegyzékszám |
| postal_code, city, address_line | string | székhely |
| country_code | char(2) default `HU` | |
| email, phone | string nullable | |
| logo_path | string nullable | |
| invoice_header_text, invoice_footer_text | text nullable | bizonylatfejléc/lábléc |
| base_currency | char(3) default `HUF` | |
| is_active | boolean default true | |
| nav_environment | enum(`test`,`production`) default `test` | melyik `company_nav_credentials.environment` sort használja a `SendInvoiceToNavJob` — utólag, 2026-06-30-án adva hozzá |
| group_prefix | string(4) nullable, globally unique | értékesítő-csoport prefix (pl. `DEMO`); csak nagybetű, max 4 karakter; CHECK: IS NULL OR `^[A-Z]+$`; nem törölhető, ha a cégnek van `sales_groups` sora; utólag, 2026-07-15-én adva hozzá |
| timestamps | | |

Index: unique(`tax_number`); unique(`group_prefix`).

### `company_bank_accounts`
| mező | típus |
|---|---|
| id, company_id (FK) | |
| bank_name | string |
| account_number | string (IBAN vagy magyar formátum) |
| currency | char(3) |
| is_default | boolean |
| timestamps | |

Index: (`company_id`, `is_default`).

### `company_nav_credentials`
| mező | típus | megjegyzés |
|---|---|---|
| id, company_id (FK) | | |
| environment | enum(`test`,`production`) | |
| nav_tax_number | string | |
| nav_login | string encrypted | technikai felhasználó |
| nav_password | string encrypted | |
| nav_signing_key | string encrypted | |
| nav_exchange_key | string encrypted | |
| is_active | boolean | |
| timestamps | | |

Index: unique(`company_id`, `environment`). Érzékeny mezők Laravel `encrypted` cast-tal.

---

## 2. Felhasználók, cégtagság, csoportok, jogosultságok

### `users` (Laravel default + bővítés)
id, name, email (unique), password, default_company_id (FK nullable → companies, utoljára aktív cég),
is_active, timestamps.

### `company_user` (pivot, M:N users↔companies)
id, company_id (FK), user_id (FK), is_default (boolean), timestamps.
Index: unique(`company_id`, `user_id`).

### `groups` (szerepkörök, cégenként saját)
id, company_id (FK), name, description nullable, is_system (boolean — pl. "Tulajdonos" törölhetetlen),
timestamps.
Index: unique(`company_id`, `name`).

### `user_group` (pivot, M:N users↔groups)
id, group_id (FK), user_id (FK), timestamps.
Index: unique(`group_id`, `user_id`).

### `permissions` (katalógus, globális — modul.művelet kulcsok)
id, key (string, pl. `invoice.create`, `invoice.cancel`, `product.edit`, `user.manage`), module (string,
pl. `invoice`), description nullable, is_sensitive (boolean — pl. sztornó, jogosultságkezelés), timestamps.
Index: unique(`key`).

### `group_permissions` (pivot, M:N groups↔permissions)
id, group_id (FK), permission_id (FK), timestamps.
Index: unique(`group_id`, `permission_id`).

### `user_permission_overrides` (egyedi felülbírálás, cégenként)
id, user_id (FK), company_id (FK), permission_id (FK), effect (enum: `allow`,`deny`), timestamps.
Index: unique(`user_id`, `company_id`, `permission_id`).

---

## 3. Termékek, ÁFA, árak

### `vat_rates` (globális, NAV-kompatibilis kategóriák)
id, name (pl. "27% normál"), rate_percent (decimal 5,2 nullable — % alapú kulcsnál), nav_code (string —
NAV XML-hez, pl. `AAM`, `TAM`, `EUKIVETEL`, vagy a százalék-kód), is_active,
**nav_receipt_category (string nullable — utólag, eNyugta 1. fázisban adva hozzá)**, timestamps.

`nav_receipt_category` a NAV eNyugta interfész ÁFA-kategória NEVÉT tárolja (l. `enyugta_vat_categories.name`),
NEM kódot — ez a NAV eNyugta `/vat-category/list` katalógusa, MÁS értékkészlet, mint a `nav_code` (ami az
Online Számla `vatExemption` case-kódjaira, AAM/TAM/… való). L. 14. fejezet.

### `products`
id, company_id (FK), sku, name, description nullable, unit (string, pl. `db`, `óra`, `kg`),
type (enum: `product`,`service`), vat_rate_id (FK), base_price (decimal 14,2), base_currency (char 3),
is_active, timestamps.
Index: unique(`company_id`, `sku`).

### `product_prices` (devizánkénti/idősoros ár)
id, product_id (FK), currency (char 3), price (decimal 14,2), valid_from (date), valid_to (date nullable),
timestamps.
Index: (`product_id`, `currency`, `valid_from`).

---

## 4. Partnertörzs

### `partners`
id, company_id (FK), type (enum: `customer`,`supplier`,`both`), name, tax_number nullable,
eu_tax_number nullable, registration_number nullable,
billing_postal_code, billing_city, billing_address_line, billing_country_code (char 2, default `HU` — utólag, 2026-07-17-én adva hozzá, l. `countries` katalógus),
shipping_postal_code nullable, shipping_city nullable, shipping_address_line nullable,
default_payment_method_id (FK nullable → payment_methods), default_currency (char 3),
email nullable, phone nullable, bank_account_number nullable, is_active, timestamps.
Index: (`company_id`, `tax_number`), unique(`company_id`, `name`) opcionális.

`billing_country_code` validációja mindig a TELJES `config('countries')` listán fut
(nem a `countries.enabled` szűrt halmazon) — az `enabled` mező csak a partner-űrlap
legördülőjét szűri, hogy egy utólag letiltott ország ne törje meg a már mentett
partnereket.

---

## 5. Fizetési módok, bizonylatsorszámok

### `payment_methods` (globális lookup, bővíthető)
id, code (unique, pl. `cash`,`card`,`bank_transfer`,`simplepay`), name, is_active, timestamps.

### `document_series` (cégenkénti/típusonkénti folytonos sorszámtartomány)
id, company_id (FK), document_type (enum: `invoice`,`receipt`), prefix (string, pl. `SZ`,`NY`),
reset_yearly (boolean), last_reset_year (smallint nullable), next_number (integer, számláló), timestamps.
Index: unique(`company_id`, `document_type`, `prefix`).

> A `next_number` növelése pesszimista zárolással (`lockForUpdate`) történik a service rétegben, hogy
> párhuzamos kiállításnál se legyen duplikáció vagy kihagyás (NAV-elvárás: folytonos, rés nélküli sorszám).

---

## 6. Számlázás

### `invoices`
| mező | típus | megjegyzés |
|---|---|---|
| id, company_id (FK), partner_id (FK), document_series_id (FK) | | |
| invoice_number | string | generált, pl. `SZ-2026-000123` |
| issue_date, fulfillment_date, due_date | date | |
| currency | char(3) | |
| exchange_rate | decimal(14,6) | kiállításkori MNB középárfolyam |
| exchange_rate_date | date | |
| payment_method_id (FK) | | |
| status | enum(`draft`,`issued`,`storno`) | bizonylat saját életciklusa |
| payment_status | enum(`open`,`partial`,`paid`) | |
| net_total, vat_total, gross_total | decimal(14,2) | számla pénznemében |
| gross_total_base_currency | decimal(14,2) | cég alap pénznemében (riportoláshoz) |
| storno_of_invoice_id | FK nullable, self | ha ez a számla egy korábbit érvénytelenít |
| nav_status | enum(`not_applicable`,`pending`,`sent`,`confirmed`,`error`) | |
| nav_transaction_id | string nullable | |
| nav_sent_at | timestamp nullable | |
| pdf_path | string nullable | |
| notes | text nullable | |
| created_by | FK → users | |
| timestamps | | nincs soft delete |

Index: unique(`company_id`, `invoice_number`); (`company_id`,`status`); (`company_id`,`payment_status`);
(`company_id`,`issue_date`); unique(`storno_of_invoice_id`) — egy eredeti számlához legfeljebb egy sztornó tartozhat.

### `invoice_items`
id, invoice_id (FK), product_id (FK nullable — szabad szöveges tétel is lehet), description,
quantity (decimal 14,3), unit, unit_price (decimal 14,2), vat_rate_id (FK), discount_percent nullable,
net_amount, vat_amount, gross_amount (decimal 14,2), sort_order (smallint), timestamps.
Index: (`invoice_id`).

### `nav_submission_logs` (minden beküldési kísérlet, retry-kezeléshez)
id, invoice_id (FK), attempt_number (smallint), request_xml (text nullable), response_xml (text nullable),
status (enum: `success`,`error`), error_message (text nullable), created_at.
Index: (`invoice_id`, `attempt_number`).

---

## 7. Nyugta

### `receipts`
id, company_id (FK), partner_id (FK nullable — nyugtánál nem kötelező), document_series_id (FK),
receipt_number, issue_date, currency, exchange_rate, exchange_rate_date, payment_method_id (FK),
status (enum: `issued`,`storno`), net_total, vat_total, gross_total, storno_of_receipt_id (FK nullable,
self), pdf_path nullable, created_by (FK → users),
**reported_at (timestamp nullable), receipt_report_id (FK → receipt_reports, nullable, restrict — l. 15. fejezet, utólag, eNyugta 2. fázisban adva hozzá)**,
timestamps.
Index: unique(`company_id`, `receipt_number`); (`company_id`,`issue_date`); (`company_id`,`reported_at`); (`receipt_report_id`) — utóbbi explicit, mert PostgreSQL FK-oszlopra nem hoz létre automatikusan indexet.

`reported_at` kitöltése ZÁROLJA a nyugtát (NAV eNyugta D2 — l. 15. fejezet): a `Receipt` modell
`saving`/`deleting` guardja ilyenkor csak a `reported_at`/`receipt_report_id` mezők változását engedi,
minden más módosítást és bármilyen törlést `ReceiptAlreadyReportedException`-nel elutasít.

### `receipt_items`
id, receipt_id (FK), product_id (FK nullable), description, quantity, unit_price, vat_rate_id (FK),
net_amount, vat_amount, gross_amount, sort_order, timestamps.

---

## 8. Fizetések, SimplePay, árfolyam

### `payments` (polimorf, számlához és nyugtához is)
id, company_id (FK), payable_type, payable_id (polymorphic → `Invoice`|`Receipt`),
payment_method_id (FK), amount (decimal 14,2), currency, paid_at (timestamp), reference (string nullable —
banki közlemény/azonosító), created_by (FK → users), timestamps.
Index: (`payable_type`,`payable_id`); (`company_id`,`paid_at`).

### `simplepay_transactions`
id, company_id (FK), invoice_id (FK), order_ref (string, SimplePay `orderRef`, unique),
transaction_id (string nullable), amount, currency, status (enum: `started`,`in_progress`,`success`,
`fail`,`timeout`,`cancel`), ipn_payload (json nullable, nyers IPN), ipn_received_at (timestamp nullable),
finished_at (timestamp nullable), timestamps.
Index: unique(`order_ref`); (`invoice_id`).

### `exchange_rates` (MNB napi középárfolyam cache)
id, currency_code (char 3), rate_date (date), rate (decimal 14,6), unit (smallint default 1 — pl. JPY-nál
100), source (string default `mnb`), timestamps.
Index: unique(`currency_code`, `rate_date`).

---

## 9. Audit log

### `audit_logs`
id, company_id (FK nullable — pl. login esemény céghez nem köthető), user_id (FK nullable — rendszer
esemény esetén null), action (string, pl. `invoice.cancel`), auditable_type, auditable_id (polymorphic),
old_values (json nullable), new_values (json nullable), ip_address (string nullable),
user_agent (string nullable), created_at (csak created_at, a log immutábilis).
Index: (`company_id`,`created_at`); (`auditable_type`,`auditable_id`).

---

## 10. Modulkezelő (1. fázis — katalógus)

### `modules` (globális katalógus — NEM cég-szintű, nincs BelongsToCompany)
| mező | típus | megjegyzés |
|---|---|---|
| id | bigIncrements | |
| key | string, unique | pl. `invoicing`, `nav`, `simplepay` |
| name | string | emberi névfeltüntetés |
| description | text nullable | |
| version | string | descriptor által adott verzió |
| is_core | boolean default false | core modul = nem kapcsolható ki, nincs pivot-sora |
| is_available | boolean default true | false = descriptor eltűnt a kódból (sor marad, nem törlődik) |
| sort_order | int nullable | |
| timestamps | | |

Index: unique(`key`). Igazságforrás: a kódban élő `ModuleDescriptor` osztályok; a tábla az `erp:sync-modules` parancs futtatásakor frissül (upsert, sosem töröl sort).

### `company_module` (pivot — cég ↔ modul M:N, tenant-szintű állapot)
| mező | típus | megjegyzés |
|---|---|---|
| id | bigIncrements | |
| company_id | FK → companies | `cascadeOnDelete`: cég törlésénél a pivot-sorai is törlődnek |
| module_id | FK → modules | `restrictOnDelete`: modul NEM törölhető, amíg cég hivatkozik rá |
| enabled | boolean default false | |
| enabled_at | timestamp nullable | mikor kapcsolták be utoljára |
| enabled_by | FK → users nullable | `nullOnDelete`: user törlésénél null lesz (audit-sor megmarad) |
| timestamps | | |

Index: unique(`company_id`, `module_id`). Core moduloknak (`is_core=true`) NINCS soruk ebben a táblában — azok mindig aktívak. Új cég létrehozásakor nem kap alapértelmezett sorokat (minden opcionális modul alapból ki van kapcsolva).

---

## 11. Értékesítő csoportok modul (`sales_group`)

> Opcionális modul. Csak akkor aktív, ha a cég `companies.group_prefix` mezője ki van töltve.
> A megjelenített csoportnév soha nem kerül tárolásra — mindig `prefix + '_' + name` alakban
> kerül felhasználásra (pl. `DEMO_Észak`).

### `companies.group_prefix` (meglévő tábla, utólag bővítve)
Lásd fent a `companies` táblánál. Röviden: VARCHAR(4), nullable, globálisan unique,
CHECK `(IS NULL OR '^[A-Z]+$')`, csak nagybetűk.

### `sales_groups`
| mező | típus | megjegyzés |
|---|---|---|
| id | bigIncrements | |
| company_id | FK → companies, restrict | BelongsToCompany trait — globális Eloquent scope szűri |
| name | string | tárolt „alapnév" (pl. `Észak`); megjelenítéskor: `prefix_name` |
| timestamps | | |

Index: `UNIQUE (company_id, LOWER(name))` — funkcionális PostgreSQL expression index, kis- és nagybetűtől független egyediség cégen belül. Sima `unique(company_id, name)` szándékosan NINCS.

Üzleti szabályok:
- Modul bekapcsolt → prefix kötelező (`companies.group_prefix NOT NULL`) a csoport létrehozása előtt (422 ha hiányzik)
- Prefix `PUT /api/company`-on nem állítható NULL-ra, amíg van sales_groups sor (422)
- Prefix megváltoztatható (csak törlés tilos, ha van csoport)
- 2. fázisban (nem most): `sales_group_user` pivot (user_id, company_id, sales_group_id)

---

## 12. Eszközök modul (assets)

> Opcionális modul. Fizikai eszközök (pl. POS terminálok, telefonok) cégenkénti
> nyilvántartása. A megjelenített/tárolt eszköznév szerver-generált és soha nem
> szerkeszthető — lásd lent.

### `asset_types`
| mező | típus | megjegyzés |
|---|---|---|
| id | bigIncrements | |
| company_id | FK → companies, nullable, restrict | `NULL` = globális alaptípus (mindenki látja); kitöltött = egy adott cég saját bővítése (csak az a cég látja) |
| code | string | rövid kód (pl. `TEYA`, `MOBIL`) — bekerül a generált eszköznévbe |
| name | string | ember-olvasható címke (pl. „Teya POS terminál”) |
| timestamps | | |

Index: composite `unique(company_id, code)` (céges bővítések cégen belüli egyedisége) **+**
külön parciális unique index `code`-ra `WHERE company_id IS NULL` (globális kódok
egyedisége — a composite unique ezt NEM fedné, mert Postgres a NULL-t sosem tekinti
egyenlőnek NULL-lal). Globális alaptípusok (`MOBIL`, `TEYA`, `PRINTER`) az
`AssetTypeSeeder`-ből, idempotens upserttel.

### `assets`
| mező | típus | megjegyzés |
|---|---|---|
| id | bigIncrements | |
| company_id | FK → companies, restrict | BelongsToCompany trait — globális Eloquent scope szűri |
| name | string | **szerver-generált**, `{CÉG_PREFIX}_{TÍPUS_KÓD}_{5-jegyű sorszám}` (pl. `DEMO_TEYA_00001`); a `CÉG_PREFIX` a `companies.group_prefix`-ből jön (ugyanaz a mező, amit a sales_group modul is használ); soha nem módosítható update-ben |
| serial_number | string | gyári szám, felhasználói bevitel |
| imei | string nullable | opcionális, felhasználói bevitel |
| asset_type_id | FK → asset_types, restrict | a típus nem módosítható a létrehozás után (a name kódolja a típus kódját) |
| status | enum(`active`,`issued`,`service`,`scrapped`) default `active` | |
| timestamps | | |

Index: composite `unique(company_id, name)`, `unique(company_id, serial_number)`,
`unique(company_id, imei)` (NULL-biztonságos — több eszköznek lehet üres IMEI-je,
Postgres a NULL-okat nem tekinti egyenlőnek). A sorszámozás **szándékosan NEM
gapless** (ellentétben a számla/nyugta sorszámozással) — megszakadt létrehozásnál
keletkező hézag megengedett.

### `asset_number_counters`
| mező | típus | megjegyzés |
|---|---|---|
| id | bigIncrements | |
| company_id | FK → companies, restrict | |
| asset_type_id | FK → asset_types, restrict | |
| next_seq | unsignedInteger default 1 | |
| timestamps | | |

Index: unique(`company_id`, `asset_type_id`). Minimál séma a `document_series`-hez
képest (nincs prefix/reset_yearly/last_reset_year) — a névgenerálás a
`document_series`/`InvoiceNumberGenerator` bevált `lockForUpdate()` +
`DB::transaction()` + create-on-first-use mintáját veszi át (`AssetNumberGenerator`),
de a hézag-megengedettség miatt a gapless-infrastruktúra súlya nem kell.

Üzleti szabályok:
- `AssetType` egyedi láthatósági scope: "company_id IS NULL VAGY = current" — NEM a
  standard `BelongsToCompany` trait, mert az plain egyenlőséget szűrne, nem
  "globális VAGY saját"-ot. Létrehozáskor nem tölti fel automatikusan a
  `company_id`-t (ellentétben a trait-tel) — egy új típus alapból globális marad,
  hacsak a hívó (az AssetType API) explicit meg nem adja.
- `canBeDeleted()` az `Asset` modellen — ma mindig `true` (nincs hozzárendelés-funkció
  még), de a döntési pont már él, jövőbeli bővítéshez előkészítve.

---

## 13. Országtörzs (`countries`)

> Globális, superadmin által kezelt katalógus — a `modules` tábla mintáját követi.
> Igazságforrás-elv: `config/countries.php` a teljes ISO 3166-1 alpha-2 kódlista (249
> kód) MESTERE, ez a validáció egyetlen forrása; a `countries` tábla ennek
> PROJEKCIÓJA + a kapcsolható `enabled` állapot. A kódlista nem a DB-ből "születik",
> a DB-t a configból szinkronizálja az `erp:sync-countries` parancs (idempotens,
> sosem ír felül meglévő `enabled` állapotot, sosem töröl sort).

### `countries` (globális katalógus — NEM cég-szintű, nincs BelongsToCompany)
| mező | típus | megjegyzés |
|---|---|---|
| id | bigIncrements | |
| code | char(2), unique | ISO 3166-1 alpha-2, a `config('countries')` egy eleme |
| enabled | boolean default true | superadmin kapcsolja; csak a partner-űrlap legördülőjét szűri, a validációt nem |
| timestamps | | |

Index: unique(`code`). API: `GET /api/countries` (bármely authentikált user, csak az
`enabled=true` kódok — a nevet a frontend adja `Intl.DisplayNames`-szel), `GET/PUT
/api/admin/countries` (superadmin-only, a PUT "replace the enabled set" szemantikával).

---

## 14. NAV eNyugta modul (1. fázis — alapinfrastruktúra)

> Opcionális modul (`enyugta`), függ a `receipts` modultól (ma core, l.
> `docs/progress.md` NAV eNyugta 1. fázis bejegyzés). Ez a fázis csak
> hitelesítő adatot + üzemmódot + globális áfa-kategória cache-t tárol —
> NINCS napi összesítő aggregáció, XML-builder, tényleges HTTP-hívás vagy
> frontend UI (azok a 2-3. fázisban). Forrás:
> [nav-enyugta-spec-jegyzetek.md](nav-enyugta-spec-jegyzetek.md).

### `company_enyugta_credentials`
| mező | típus | megjegyzés |
|---|---|---|
| id, company_id (FK, **unique**) | | cégenként EGY sor — ellentétben a `company_nav_credentials` environmentenkénti több sorával (l. D1 indoklás progress.md-ben) |
| login | string encrypted | technikai felhasználó (nem tudni, megosztható-e az Online Számláéval) |
| password | text encrypted | SHA3-512 hash-elve épül be a kérésbe, nyersen soha nem megy ki |
| signing_key | text encrypted | |
| exchange_key | text encrypted | |
| tax_number | string | bare 8 jegyű törzsszám, ugyanaz a formátum, mint `company_nav_credentials.nav_tax_number` |
| mode | enum(`mock`,`test`,`live`) default `mock` | D2 döntés — `mock` HTTP-hívás nélkül fut le, séma-konform fix választ ad |
| base_url_override | string nullable | a spec nem közöl teljes base URL-t (csak a `/receipt-if` context rootot), cégenkénti felülbírálásra |
| send_empty_reports | boolean default false | D4 döntés — nullás nap jelentése legyártásra kerül, de alapból nem kerül beküldésre |
| last_verified_at | timestamp nullable | |
| timestamps | | |

Index: unique(`company_id`). Titkos mezők (`login`/`password`/`signing_key`/`exchange_key`) `encrypted`
cast + `#[Hidden]` a modellen — a `GET /api/settings/enyugta` csak `has_*` boolokat ad vissza, sosem a
nyers értéket.

### `enyugta_vat_categories` (globális cache — NEM cég-szintű, nincs BelongsToCompany)
| mező | típus | megjegyzés |
|---|---|---|
| id | bigIncrements | |
| name | string, **unique** | a NAV `/vat-category/list` válaszának `categories/category/name` mezője, szó szerint |
| synced_at | timestamp | utolsó szinkronizálás időpontja |
| timestamps | | |

Index: unique(`name`) — ez az upsert kulcsa is (`erp:sync-enyugta-vat-categories`, idempotens).
**A séma D3 döntés szerint egyszerűsítve lett a tervezett `code`/`rate`/`valid_from`/`valid_to` mezőkből
`name`-re** — a NAV válasz (jegyzet 2.1.9.2, 30. oldal) kizárólag nevet ad vissza, és a `valid_from`
Postgres NULL-szemantikája miatt az eredeti összetett unique index nem védett volna duplikátum ellen. Ha a
NAV a jövőben bővíti a választ, külön migrációval vezetendő be.

---

## 15. NAV eNyugta modul (2. fázis — napi összesítő, aggregáció, zárolás)

> Napi nyugta-adatszolgáltatás összesítő (`docs/progress.md` NAV eNyugta 2. fázis, D1-D7 döntések).
> NINCS ebben a fázisban: tényleges NAV HTTP-beküldés, XML-builder, frontend UI.

### `receipt_reports`
| mező | típus | megjegyzés |
|---|---|---|
| id, company_id (FK) | | |
| report_date | date | a nap, amire a jelentés vonatkozik (D5: `receipts.issue_date`, Europe/Budapest) |
| type | enum(`normal`,`correction`) default `normal` | D1 — a beküldött jelentés immutábilis, változás esetén korrekció, nem felülírás |
| original_report_id | FK → receipt_reports, nullable, **restrict** | csak `type=correction` esetén kitöltött; mindig a nap NORMÁL jelentésére mutat, korrekciók nincsenek láncolva; **restrictOnDelete**, mert `nullOnDelete` ütközne a CHECK constrainttel |
| status | enum(`draft`,`ready`,`sending`,`accepted`,`rejected`) default `draft` | a `sending`/`accepted`/`rejected` a 3. fázisban kap tényleges jelentést (előre felvéve, hogy ne kelljen enum-bővítő migráció) |
| receipt_count | integer | a napon szereplő ÖSSZES nyugta száma (nem a soronkénti összeg — egy nyugta több kategóriában is szerepelhet) |
| total_net, total_vat, total_gross | decimal(14,2) | D6: a nyugtatételeken már tárolt összegek összege, egész forintra kerekítve |
| transaction_id | string nullable | 3. fázis (NAV tranzakcióazonosító) |
| submitted_at | timestamp nullable | 3. fázis |
| response_payload | jsonb nullable | 3. fázis (NAV válasz) |
| error_message | text nullable | 3. fázis |
| retry_count | integer default 0 | 3. fázis |
| generated_at | timestamp | a `ReceiptReportBuilder` legutóbbi futásának időpontja |
| timestamps | | |

Index: (`company_id`, `report_date`). **Parciális unique index** `receipt_reports_one_normal_per_day`
(`company_id`, `report_date`) `WHERE type = 'normal'` — cégenként/naponta pontosan egy normál jelentés,
korrekcióból több is lehet. **CHECK constraint** `receipt_reports_correction_requires_original`:
`type='normal' → original_report_id IS NULL`, `type='correction' → original_report_id IS NOT NULL`.

### `receipt_report_lines`
| mező | típus | megjegyzés |
|---|---|---|
| id, receipt_report_id (FK, cascade) | | |
| nav_receipt_category | string | a NAV eNyugta kategória NEVE — az EGYETLEN csoportosítási kulcs (D4) |
| net_amount, vat_amount, gross_amount | decimal(14,2) | |
| receipt_count | integer | a kategóriát tartalmazó DISZTINKT nyugták száma |
| timestamps | | |

Index: (`receipt_report_id`). **NINCS `company_id`** (a szülőn, `receipt_reports`-on keresztül scope-olt) és
**NINCS `vat_rate_id`** — utóbbi szándékos: egy `restrictOnDelete` FK örökre megkötné a `vat_rates`
törzsadat-sort, amint egyszer belekerült egy immutábilis jelentésbe, és elméletileg több áfakulcs is
ugyanarra a NAV-kategóriára képezhető (egy néha NULL, néha kitöltött FK félrevezető adat lenne).

### Üzleti szabályok
- **D2 (nyugta-zárolás):** a `Receipt` modell `saving`/`deleting` guardja — ha `reported_at` ki van
  töltve, csak a `reported_at`/`receipt_report_id` mezők változása engedett, minden más
  `ReceiptAlreadyReportedException`-t dob. A `ReceiptReportBuilder` pontosan ezt a két mezőt írja,
  ezért a guard nem akasztja meg saját magát.
- **D4 (áfa-megfeleltetés kötelező):** ha egy érintett `vat_rate.nav_receipt_category` `NULL`, az
  aggregáció `ReceiptReportBuildException`-t dob — nem tippel, nem hagyja ki csendben a sort.
- **Devizakérdés (dokumentált feltételezés, nem a spec/feladatleírás explicit döntése):** az
  aggregáció NEM végez árfolyam-konverziót; nem-HUF nyugta esetén szintén
  `ReceiptReportBuildException`-t dob.
- **D7 (CSV export):** `GET /api/enyugta/reports/{report}/export` — vészkijárat a KOBAK-portálon
  való kézi rögzítéshez, mivel a NAV gépi interfész bázis-URL-je nincs publikálva.

---

## Kapcsolati összefoglaló (legfontosabbak)

- `companies` 1—N `company_bank_accounts`, `company_nav_credentials`, `company_enyugta_credentials`,
  `products`, `partners`, `invoices`,
  `receipts`, `groups`, `document_series`, `sales_groups`, `assets`, `asset_number_counters`, `receipt_reports`
- `receipt_reports` 1—N `receipt_report_lines`, `receipts` (a jelentéshez tartozó nyugták);
  `receipt_reports` önhivatkozó (`original_report_id`) — korrekció → normál jelentés
- `asset_types` 1—N `assets`; `asset_types.company_id` nullable (globális VAGY céges sor)
- `companies` M—N `users` (`company_user`)
- `companies` M—N `modules` (`company_module`, csak opcionális modulok)
- `groups` M—N `users` (`user_group`); `groups` M—N `permissions` (`group_permissions`)
- `users` M—N `permissions` cégenként, override-dal (`user_permission_overrides`)
- `invoices` 1—N `invoice_items`, `nav_submission_logs`, `payments` (polymorphic)
- `receipts` 1—N `receipt_items`, `payments` (polymorphic)
- `invoices`/`receipts` önhivatkozó sztornó-lánc (`storno_of_*_id`)
- `products` 1—N `product_prices`; `products` N—1 `vat_rates`
- `partners` 1—N `invoices`, `receipts`

---

## Nyitott kérdések / döntésre vár

1. `groups` és jogosultság-örökítés: kell-e hierarchikus csoport (csoportok egymásba ágyazása), vagy lapos
   lista elég? → **v1: lapos lista**, később bővíthető.
2. `partners` egyedi `tax_number` kényszerítve legyen-e cégen belül? (Jelenleg csak index, nem unique, mert
   magánszemély partnernek nincs adószáma.)
3. Riport/raktárkészlet modul a jövőben épül majd a `products` táblára — ebben a körben nem készül tábla rá.

> A megvalósítás tényleges állapota (kész lépések, ismert hiányosságok, demo bejelentkezés)
> a [progress.md](progress.md)-ben — ez a doksi a *tervet* írja le, a progress.md a *tényt*.
