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
| timestamps | | |

Index: unique(`tax_number`).

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
NAV XML-hez, pl. `AAM`, `TAM`, `EUKIVETEL`, vagy a százalék-kód), is_active, timestamps.

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
billing_postal_code, billing_city, billing_address_line,
shipping_postal_code nullable, shipping_city nullable, shipping_address_line nullable,
default_payment_method_id (FK nullable → payment_methods), default_currency (char 3),
email nullable, phone nullable, bank_account_number nullable, is_active, timestamps.
Index: (`company_id`, `tax_number`), unique(`company_id`, `name`) opcionális.

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
(`company_id`,`issue_date`); (`storno_of_invoice_id`).

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
self), pdf_path nullable, created_by (FK → users), timestamps.
Index: unique(`company_id`, `receipt_number`); (`company_id`,`issue_date`).

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

## Kapcsolati összefoglaló (legfontosabbak)

- `companies` 1—N `company_bank_accounts`, `company_nav_credentials`, `products`, `partners`, `invoices`,
  `receipts`, `groups`, `document_series`
- `companies` M—N `users` (`company_user`)
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
