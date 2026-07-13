# Élesítési útmutató

> **Egy igazságforrás:** a funkció-állapot, az architekturális konvenciók és a
> go-live ellenőrzőlista a [`docs/progress.md`](progress.md)-ben van. Ez a fájl
> a telepítési sorrendet és a „mit szabad / mit nem szabad élesben futtatni"
> kérdéseket rögzíti.

---

## Kötelező élesítési szekvencia

Az alábbi parancsokat **sorrendben** kell futtatni minden éles telepítésnél
(első telepítés és minden frissítés). A sorrend nem cserélhető fel — minden
lépés az előző kimenettől függ.

> **Parancsok futtatási formája:** a `php artisan ...` minták ebben a dokumentumban
> a **production** környezetre vonatkoznak — ott nincs Sail. Fejlesztői (Docker)
> környezetben MINDIG `./vendor/bin/sail artisan ...` alakot kell használni
> (ld. `docs/progress.md` → Architekturális konvenciók — *FEJLESZTŐI artisan/sail
> parancsok — TILOS a `docker compose exec` root-ként*). Az éles szerveren az
> artisan parancsok tényleges futtatási módja (pl. `sudo -u www-data php artisan`,
> CI/CD pipeline, deploy script) **az éles környezet kialakításakor véglegesítendő**.

### 1. Adatbázis-séma frissítése

```bash
php artisan migrate --force
```

**Miért `--force`?** Laravel `APP_ENV=production` esetén interaktív megerősítést
kér minden `migrate` híváshoz. Deploy-scriptből automatizált futtatásnál ez
akadályt jelent; a `--force` ezt tudatosan megkerüli. A kockázatot az
visszafordíthatatlan migrációkra vonatkozó kódvizsgálati elvárások kezelik,
nem a runtime prompt.

---

### 2. Rendszer-seed futtatása

```bash
php artisan db:seed --force
```

**Mit csinál:** betölti a `PermissionSeeder`, `VatRateSeeder`,
`PaymentMethodSeeder` és `TranslationSeeder` katalógusait. Ezek **kötelező
rendszer-adatok** — nélkülük az RBAC, az ÁFA-számítás és a fizetési módok
nem működnek.

**Idempotens:** minden seeder `updateOrCreate`/`firstOrCreate` logikával ír —
többszöri futtatás biztonságos, nem hoz létre duplikált sorokat.

**Miért `--force`?** Ugyanaz az ok, mint a migrate esetén: `production`
környezetben a Laravel interaktív megerősítést kér, a `--force` ezt
automatizált kontextusban kikerüli.

**Demo-adat NEM fut:** a `DatabaseSeeder` `App::environment('local', 'testing')`
feltétel mögé zárja a demo-user és a `DemoDataSeeder` hívását. Production
környezetben ezek a sorok nem hajtódnak végre. Részletek: lásd lentebb,
[Ami NEM futhat élesben](#ami-nem-futhat-élesben) szakasz.

---

### 3. Modul-katalógus szinkronizálása

```bash
php artisan erp:sync-modules
```

**Mit csinál:** a `ModuleRegistry` PHP-descriptorait upserteli a `modules`
DB-táblába. Sohasem töröl sorokat — eltűnt descriptorok `is_available=false`-ra
állnak.

**Miért kötelező?** A modul-**gating** (biztonság) a kódból (descriptorokból)
dolgozik, ezért üres `modules` tábla esetén is fail-closed marad — a gating
helyesen működik. Azonban az admin felület (`/settings/modules`) üres listát
mutat, és a modulok be-/kikapcsolása 404-et ad, amíg ez a lépés nem fut le.

---

### 4. Szinkron ellenőrzése

```bash
php artisan erp:check-permissions
```

**Exit kód:**
- `0` — katalógus szinkronizált, nincs jogosultság-hézag
- `1` — legalább egy modul vagy jogosultság nincs szinkronizálva

**Mit ellenőriz:**
- A `modules` tábla tartalmazza-e az összes registry-descriptort
- A `permissions` tábla tartalmazza-e az összes descriptor által hirdetett kulcsot
- Nincs-e árva kulcs (DB-ben van, descriptor nem hirdeti)

Ha `1`-gyel tér vissza, az előző lépéseket kell megismételni, illetve a
`PermissionSeeder`-t ellenőrizni.

---

### 5. Szuperadmin létrehozása (CSAK első telepítéskor)

```bash
php artisan erp:create-superadmin
```

**Csak az első telepítésnél szükséges.** Frissítésnél kihagyható — a meglévő
superadmin-fiók megmarad.

**Interaktív**: emailt, nevet és jelszót kérdez. A jelszó nem kerül logba.
Production környezetben futtatáskor a parancs megerősítést kér — ez kihagyható
a `--force` flaggel (CI/automatizált deploy esetén).

---

### 6. Fájlrendszer-jogosultságok — PDF-tár

A `storage/app/private/documents/` könyvtár **írható a webszerver user által** és
**olvasható a backup user által** kell, hogy legyen. Ez **környezetfüggetlen elvárás**
— a megvalósítás módja eltér:

| Környezet | Webszerver user | Artisan futtatás | Elvárt tulajdonos |
|-----------|-----------------|------------------|-------------------|
| Fejlesztői (Docker/Sail) | `sail` (UID=1000) | `./vendor/bin/sail artisan` | `szolke:szolke 755` |
| Éles (production) | pl. `www-data`, `nginx`, `deploy` | TBD az éles env kialakításakor | webszerver user |

**Ha a könyvtár nem a webszerver user tulajdona** (pl. root hozta létre), a PHP
process nem tud PDF-et menteni → `500 UnableToCreateDirectory` hiba keletkezik.

Javítás (éles környezetben, webszerver user = pl. `www-data`):

```bash
chown -R www-data:www-data storage/app/private/documents
chmod -R 755 storage/app/private/documents
find storage/app/private/documents -type f -exec chmod 644 {} \;
```

**Ellenőrzés deploy után:**

```bash
ls -la storage/app/private/documents/
# Elvárt: webszerver user tulajdona, drwxr-xr-x (755) könyvtárak, -rw-r--r-- (644) fájlok
```

**Backup-jogosultság:** a backup user-nek olvasási jogot kell kapnia a `documents/` fára
(pl. ACL, group-hozzáadás). A tárolt PDF-ek jogi bizonyíték-értékűek — elvesztésük
visszafordíthatatlan. Részletek: `docs/progress.md` Nyitott pont #7.

---

## Ami NEM futhat élesben

### DemoDataSeeder

A `DemoDataSeeder` **fejlesztési/tesztelési adat**, nem valódi rendszer-adat.
Tartalmaz:
- `Demo Kft.` céget (`11111111-1-42` adószámmal)
- Teszt-csoportokat
- Dummy NAV technikai hitelesítő adatokat

**Kettős védelem biztosítja, hogy production-ban ne fusson:**

1. `DatabaseSeeder::run()` az egész demo-blokkot `App::environment('local', 'testing')`
   feltétel mögé zárja — normál `db:seed` esetén production-ban nem fut.
2. `DemoDataSeeder::run()` elejére saját guard kerül — közvetlen
   `db:seed --class=DemoDataSeeder` hívás esetén is abortál production-ban.

### Demo-user (`test@example.com` / `password`)

A `test@example.com` / `password` kombinációjú `is_superadmin=true` felhasználó
**kizárólag `local` és `testing` környezetben** jön létre (a `DatabaseSeeder`
env-guard-ja védi). Production-ban ez a sor soha nem hajtódik végre.

### UserFactory

A `database/factories/UserFactory.php` hardcoded `Hash::make('password')`
értéket használ — ez **kizárólag tesztekben** releváns (`RefreshDatabase` trait
mindig friss adatbázist használ). Production-ban factory-t ne használj
(`tinker`-en keresztül sem).

---

## Go-live ellenőrzőlista

Az élesítést blokkoló pontok és állapotuk: **[`docs/progress.md` — Élesítés előtti
checklist](progress.md#élesítés-előtti-checklist)** szakasz.

A lista szándékosan ott van, nem itt — egy igazságforrás.
