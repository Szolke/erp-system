# Fejlesztési állapot

> Ezt a fájlt mindig frissítsd egy-egy nagyobb lépés (commit-csoport) végén.
> Célja: egy új munkamenet (vagy kontextus-vesztés utáni folytatás) ne a
> git logból/kódból kelljen visszafejtse, hol tartunk — itt egyben megtalálja.
> Részletes tábla-/mezőszintű terv: [er-model.md](er-model.md).

Utolsó frissítés: 2026-07-01, a 12. lépés (RBAC UI + bizonylat-beállítások) után.

## Kész lépések

| # | Mit csinál | Fő fájlok | Commit |
|---|---|---|---|
| 1-2 | Séma (26 migráció), modellek, enumok, seederek (permissions/vat_rates/payment_methods) | `database/migrations/`, `app/Models/`, `app/Enums/`, `database/seeders/` | `538ebae`, `9547df3` |
| 3 | Sanctum SPA auth (cookie-session, nem token) + company-context middleware | `AuthController`, `EnsureCompanyContext`, `CurrentCompany` | `7e4c5f5` |
| 4 | RBAC feloldó (csoport-jog + user-override, deny felülír allow-t) `Gate::before`-ba kötve | `PermissionChecker`, `AppServiceProvider::boot()` | `f6f586e`, `d6492fc` |
| 5 | Törzsadat API-k: companies (csak saját, show/update), partners, products | `CompanyController`, `PartnerController`, `ProductController` | `2532bea` |
| 6 | Számlázási mag: gapless sorszámozó (`SELECT FOR UPDATE`), invoice CRUD, sztornó-lánc | `InvoiceNumberGenerator`, `InvoiceService`, `InvoiceController` | `0a909e3` |
| 7a | NAV Online Számla 3.0 XML + queue job (aszinkron, sosem blokkol) | `NavXmlBuilder`, `SendInvoiceToNavJob`, `NavReporterFactory` | `3b6ee4c` |
| 7b | Nyugta (receipt) CRUD + sztornó — ugyanaz a minta mint invoice, NAV nélkül | `ReceiptService`, `ReceiptController` | `fbbda09` |
| 8 | MNB árfolyam-lekérdező, ütemezve hétköznap 13:00 | `MnbExchangeRateFetcher`, `FetchMnbExchangeRates` | `86d9c1e` |
| — | Queue worker + scheduler ténylegesen fut a konténerben (supervisor) | `backend/docker/8.5/supervisord.conf` | `99628b0` |
| — | NAV teszt/éles kapcsoló cégenként (`companies.nav_environment`) | migráció + `SendInvoiceToNavJob` | `e5cdd84` |
| 9 | Kézi fizetésrögzítés + SimplePay (start + IPN, HMAC-SHA384 aláírással) | `PaymentController`, `SimplePayController`, `SimplePayIpnController`, `SimplePayClient` | `0aa316e` |
| 10 | Audit log: `AuditLogger` service, bekötve login/invoice/receipt/company műveletekbe; `GET /api/audit-logs` | `AuditLogger`, `AuditLogController` | `5d5cd6e` |
| 11 | React SPA frontend: login, company-switcher, számla/nyugta/partner/termék listák+CRUD+detail, cégbeállítás (nav_environment toggle), audit napló nézet | `frontend/src/` teljes SPA | `db01b86` |
| 12a | RBAC management UI: felhasználó-lista/detail (csoporttag-kezelés + per-jog override toggle), csoport-lista/detail (jogosultság-jelölők + tagok), sidebar menüpontok | `UserListPage`, `UserDetailPage`, `GroupListPage`, `GroupDetailPage`, `UserController`, `GroupController` | — |
| 12b | Bizonylat-sorszámtartományok: külön sorozat sztornókhoz (`invoice_storno`/`receipt_storno`), szám-formátum ÉÉÉÉDÉHH (pl. 202606), beállítások oldal (`/settings/document-series`), `Sorszámtartományok` sidebar menü | `DocumentType`, `InvoiceNumberGenerator`, `InvoiceService::cancel`, `ReceiptService::cancel`, `DocumentSeriesController`, `DocumentSeriesSettingsPage` | — |
| 12c | Kis UX javítások: deviza legördülő (HUF/EUR) InvoiceCreate + ProductForm; mértékegység legördülő ProductForm; fizetési dátum szélesebb; fizetési mód select (InvoiceDetail) | `InvoiceCreatePage`, `ProductFormPage`, `InvoiceDetailPage` | — |

## Még hátravan

- **PDF-generálás** (számla/nyugta bizonylat) — `pdf_path` mező van az invoices/receipts táblában, de sem könyvtár (pl. barryvdh/laravel-dompdf), sem template, sem controller-endpoint nincs hozzá.
- **RBAC management UI/API** — **KÉSZ** (12a. lépés). Felhasználók és csoportok kezelése UI-ból elérhető.
- **Cég-onboarding** — `CompanyController` csak az aktív céget kezeli, új cég létrehozása + első felhasználó hozzárendelése nincs megépítve.
- **Frontend finomítás** — a jelenlegi frontend funkcionális első változat; szükséges lehet: hibakezelés bővítése, pagination kezelés (csak az első oldal jelenik meg), lapozó UI, Toast-értesítések sikeres/hibás műveletekre, SimplePay fizetés indítás gomb InvoiceDetailPage-ből.

## Architekturális konvenciók (amit egy új munkamenetnek tudnia kell)

- **Multi-tenant szűrés**: minden cég-szintű modell a `BelongsToCompany` trait-et használja (`app/Models/Concerns/BelongsToCompany.php`) — globális scope a `CurrentCompany` singletonon keresztül (`app/Support/CurrentCompany.php`), amit az `EnsureCompanyContext` middleware tölt fel kérésenként. Service/job kontextusban (ahol nincs middleware) explicit `withoutGlobalScope('company')`-t kell használni, ha a company_id-t kézzel adjuk meg (lásd `InvoiceNumberGenerator`, `PermissionChecker`).
- **RBAC**: jogosultság-kulcsok `modul.művelet` formában (`app/Models/Permission`, seedelve `PermissionSeeder`-ből). Controllerben `$this->authorize('invoice.cancel')` vagy `$user->can(...)` — mindkettő a `Gate::before`-ba kötött `PermissionChecker`-en megy át (`AppServiceProvider::boot()`).
- **Service réteg**: minden komolyabb üzleti logika (`InvoiceService`, `ReceiptService`, `PaymentStatusUpdater`, `NavXmlBuilder`, `SimplePayClient`) `app/Services/` alatt, controllerek vékonyak maradnak.
- **DB-default mezők**: `Model::create()` után **mindig** `->refresh()` kell, ha a válaszban DB-szintű default értéket (pl. `is_active`, `nav_status`) akarunk visszaadni — enélkül `null` jön vissza a friss objektumból. Ez a hiba már kétszer előjött (Product/Partner, majd Invoice), `refresh()`-sel javítva.
- **Seederek**: `PermissionSeeder`/`VatRateSeeder`/`PaymentMethodSeeder` = valódi katalógus-adat, mindig fusson. `DemoDataSeeder` = reprodukálható teszt-sandbox (1 cég, 2 csoport, override-ok, 1 termék/partner, NAV teszt+éles dummy hitelesítés) — idempotens (`updateOrCreate`/`sync`), bármikor újrafuttatható.
- **Worker/scheduler**: a Sail image-et publikáltuk (`backend/docker/8.5/`, NEM a `vendor/`-ból épül többé), a `supervisord.conf` futtat egy `queue-worker` (NAV job) és egy `scheduler` (MNB) processzt — ezeknek menniük kell automatikusan, nem kell kézzel `queue:work`-öt indítani.

## Demo bejelentkezés (helyi teszteléshez)

- `test@example.com` / `password`
- Aktív cég: "Demo Kft." (tax_number `11111111142` — **kötőjelek nélkül, lásd nyitott pont lent**)
- A demo user 2 csoportban van ("Pénzügy": invoice/receipt/payment jogok; "Törzsadatkezelő": product/partner/company/user/group/document_series jogok), plusz 1 explicit override (`invoice.cancel` allow) — RBAC-teszteléshez.
- Bizonylat-sorozatok: `SZ` (számla), `NY` (nyugta), `SZSZT` (sztornó számla), `NYSZT` (sztornó nyugta). Formátum: `PREFIX-ÉÉÉÉHH-000001`.

## Nyitott pontok / ismert hiányosságok

1. **NAV `vatExemption` case kódok** (AAM/TAM stb. a `vat_rates.nav_code`-ban) — a pontos XSD enumerációt nem sikerült közvetlenül kinyerni, NAV sandbox ellen kell ellenőrizni `NavXmlBuilder`-ben élesítés előtt.
2. **`tax_number` formátum** — a `companies`/`partners` tábla `tax_number` mezőjének kötőjeles formátumban (`12345678-1-42`) kell lennie, hogy a NAV XML `supplierTaxNumber`/`customerTaxNumber` helyesen szétbontható legyen (`taxpayerId`/`vatCode`/`countyCode`). **Jelenleg nincs validáció erre** a `CompanyController`/`PartnerController` FormRequest-jeiben, és a demo adat is kötőjel nélküli.
3. **SimplePay `url` mező** — a start-kérésben az egyetlen `url` mezőt használjuk visszairányításra; nem 100%-osan megerősített, hogy SimplePay nem külön success/fail/cancel/timeout URL-eket vár-e. Sandbox-tesztelés valódi merchant-adatokkal szükséges élesítés előtt.
4. **Nincs draft→issue számla-workflow** — a `POST /api/invoices` azonnal `issued` állapotban, lefoglalt sorszámmal hozza létre a számlát (tudatos egyszerűsítés, mert kiállított számla soha nem törölhető, csak sztornózható — így rés a sorszámozásban nem keletkezhet). Ha draft-szerkesztés válik szükségessé, az `invoice_number` oszlopot nullable-re kell migrálni.
5. **Nincs cég-onboarding flow** — `CompanyController` csak a meglévő aktív céget tudja megjeleníteni/szerkeszteni, új cég létrehozása (+ első felhasználó hozzárendelése) nincs megépítve.
6. **Frontend** gyakorlatilag érintetlen — minden fenti API-t csak curl/tinker-rel teszteltünk, böngészőből még semmi nem használható.

## Hogyan fuss neki gyorsan egy új munkamenetben

```bash
cd /home/szolke/projects/erp-system   # MINDIG innen futtass docker compose-t, ne a backend/-ből
docker compose ps                      # 4 konténer fusson: laravel.test, pgsql, redis, frontend
docker compose exec laravel.test php artisan migrate:status
docker compose exec laravel.test php artisan route:list --path=api
```
