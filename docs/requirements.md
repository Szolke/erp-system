# Telepítési előfeltételek

> **Kapcsolódó dokumentumok:**
> - Telepítés **lépései** → [`docs/deploy.md`](deploy.md)
> - Go-live ellenőrzőlista → [`docs/progress.md`](progress.md#élesítés-előtti-checklist)
> - PDF backup → [`docs/backup.md`](backup.md)
>
> Ez a dokumentum azt írja le, **milyen rendszer, szoftver és hozzáférés szükséges**
> a telepítés megkezdése előtt. A parancssort és a lépéseket a deploy.md tartalmazza.

---

## 1. Rendszerkövetelmények

### Operációs rendszer

| Szint | Rendszer |
|-------|---------|
| Tesztelve, ajánlott | WSL2 Ubuntu 22.04/24.04 (fejlesztői env), natív Linux (Ubuntu 22.04+) |
| Elméletileg működik | Bármely Docker-t futtató 64-bites Linux |
| Nem tesztelt | macOS (Sail támogatja, de a projekt nem tesztelve), Windows natív |

### CPU / RAM / lemez

> **Megjegyzés:** az alábbi értékek fejlesztői tapasztalaton alapuló becslések —
> éles terhelésmérési adat nem áll rendelkezésre.

| Erőforrás | Minimum | Ajánlott |
|-----------|---------|---------|
| CPU | 2 mag | 4+ mag |
| RAM | 4 GB (Docker overhead miatt) | 8 GB |
| Lemez — rendszer + Docker images | 10 GB | 20 GB |
| Lemez — PDF-archívum (külön!) | ld. alább | ld. alább |

**PDF-archívum mérete — különös figyelem szükséges:**

A `storage/app/private/documents/` könyvtár folyamatosan nő, és jogszabályi
megőrzési kötelezettség miatt nem törölhető. Aktuális (fejlesztési demo) adat:
**139 fájl = 119 MB**, az egyedi PDF-ek ~860 KB körül vannak.

Éles forgalomnál a szükséges tárhelyet az alábbi képlettel érdemes becsülni:

> **~1 MB / bizonylat × éves bizonylatszám × megőrzési évek**

A megőrzési idő **meghatározandó** (jogi/könyvelői egyeztetés folyamatban) —
ez közvetlenül befolyásolja a szükséges tárhelyet. A tárhely-tervezés során a
„megőrzési évek" helyére a jogi tanácsadóval megerősített értéket kell behelyettesíteni.
Amíg az álláspont nem megerősített, ne tervezzetek konkrét évszámra.

A PDF-archívumnak **dedikált, külön mentett** tárolón kell lennie
(ld. [`docs/backup.md`](backup.md)).

---

## 2. Kötelező szoftverek a hoston

### Docker-alapú telepítésnél (ajánlott, ez a fejlesztett és tesztelt út)

> **Fontos:** Docker-alapú telepítésnél a **PHP, Node.js, PostgreSQL és Redis
> NEM szükséges a hostra** — minden a konténerekben fut. Ez a leggyakoribb
> félreértés új rendszergazdáknál.

| Szoftver | Minimális verzió | Megjegyzés |
|---------|-----------------|-----------|
| Docker Engine | 20.10+ | Compose v2 szintaxist használ a projekt (nincs `version:` kulcs a compose.yaml-ban) |
| Docker Compose | v2.x (beépített `docker compose` parancs) | A `docker-compose` (v1, önálló bináris) NEM támogatott |
| Git | bármely modern | Repo klónozáshoz |

**Ellenőrzés:**
```bash
docker --version          # Docker version 20.10+
docker compose version    # Docker Compose version v2.x
```

### Konténerekben futó komponensek (nem kell külön telepíteni)

| Komponens | Verzió (image) | Forrás |
|-----------|----------------|--------|
| PHP | **8.5** | `backend/docker/8.5/Dockerfile` — ubuntu:24.04 + ondrej/php PPA |
| PostgreSQL | **18** (postgres:18-alpine) | compose.yaml |
| Redis | alpine (redis:alpine) | compose.yaml |
| Node.js (frontend) | **20** (node:20-alpine) | compose.yaml |
| Node.js (backend konténer) | **24** | backend/docker/8.5/Dockerfile ARG NODE_VERSION=24 |

### Bare metal telepítés (nem tesztelt, nem dokumentált)

Ha Docker nem áll rendelkezésre, az alábbi szoftverek szükségesek a hoston.
**Ez az útvonal nincs tesztelve és nem támogatott** — a projekt fejlesztési
és produkciós környezete Docker-alapú.

| Szoftver | Verzió |
|---------|--------|
| PHP | 8.5 (a composer.json ^8.3-at ír, de a fejlesztett Dockerfile 8.5-öt használ) |
| PHP kiterjesztések | `pgsql`, `gd`, `curl`, `mbstring`, `xml`, `zip`, `bcmath`, `soap`, `intl`, `redis` |
| PostgreSQL | 18 |
| Redis | bármely modern |
| Node.js | 20+ (frontend build) |
| Composer | 2.x |

---

## 3. Külső szolgáltatások és hozzáférések

> **Érzékeny adat:** valódi kulcsok, jelszavak, technikai felhasználónév soha ne
> kerüljön commitba. Kizárólag a `.env` fájlban tárolandók a szerveren.

### NAV Online Számla (kötelező a NAV-beküldési funkcióhoz)

A rendszer NAV Online Számla 3.0 API-n keresztül küldi be a számlákat.
Ezt a modul-rendszerben a `nav` modul vezérli — kikapcsolt állapotban a
beküldés nem fut, de a többi funkció (helyi számlázás, PDF) működik.

**Amit be kell szerezni a [NAV Online Számla portálról](https://onlineszamla.nav.gov.hu):**
- Technikai felhasználó (teszt és éles külön-külön)
- `nav_login` — technikai felhasználó neve
- `nav_password` — jelszava
- `nav_signing_key` — aláírókulcs
- `nav_exchange_key` — cserekulcs
- `nav_tax_number` — a cég adószáma (NAV-formátum: `12345678-1-41`)

**Hova kerül:** a rendszer admin felületén (Cég beállítások → NAV hitelesítők),
nem a `.env`-be. Titkosítva tárolódik az adatbázisban.

**NAV szoftver-azonosítás:** a `.env`-ben kell beállítani (a NAV portálján
regisztrálni kell a szoftvert):
```
NAV_SOFTWARE_ID=
NAV_SOFTWARE_NAME=
NAV_SOFTWARE_DEV_NAME=
NAV_SOFTWARE_DEV_CONTACT=
NAV_SOFTWARE_DEV_TAX=
```

### SimplePay (kötelező az online fizetési funkcióhoz)

Az `simplepay` modul vezérli — kikapcsolt állapotban a SimplePay nem aktív.

**Amit be kell szerezni az [OTP SimplePay Partner Portálról](https://simplepay.hu):**
- Sandbox és éles `SIMPLEPAY_MERCHANT_ID`
- Sandbox és éles `SIMPLEPAY_SECRET_KEY`

**Hova kerül:** `.env`:
```
SIMPLEPAY_MERCHANT_ID=
SIMPLEPAY_SECRET_KEY=
SIMPLEPAY_ENVIRONMENT=sandbox   # vagy: production
```

### MNB árfolyam API (automatikus, regisztráció nem szükséges)

Az MNB nyilvános SOAP API-t (`https://www.mnb.hu/arfolyamok.asmx?wsdl`) hívja
az ütemezett árfolyam-lekérdező. Regisztráció vagy API-kulcs nem szükséges,
de **kimenő HTTPS-hozzáférés az internethez szükséges** a szerverről.

### E-mail / SMTP

Jelenleg **nincs e-mail küldési funkció** az alkalmazásban — a `.env.example`-ban
`MAIL_MAILER=log` az alapértelmezés (csak naplózza). SMTP beállítás ezért ma
nem előfeltétel. Ha jövőben e-mail funkció kerül a rendszerbe, az `.env`-ben
a `MAIL_*` változók beállítása szükséges.

### S3 / objektumtár

Az alkalmazás jelenleg **lokális lemezt használ** PDF-tároláshoz (`Storage::disk('local')`).
Az S3-konfigurációs változók a `.env.example`-ban jelen vannak, de üresek és
nem aktívak — S3-fiók jelenleg nem szükséges előfeltétel. Ha jövőben S3-ra kerül
a PDF-tárolás, az `AWS_*` változók beállítása szükséges.

---

## 4. Hálózat és portok

### Portok (Docker-alapú telepítés)

| Port | Irány | Szolgáltatás | Szükséges kívülről? |
|------|-------|-------------|---------------------|
| **80** | bejövő | Backend API + frontend SPA (Laravel Sail) | **Igen** — ez az alkalmazás fő belépési pontja |
| **5174** | bejövő | Frontend fejlesztői szerver (Vite dev) | Csak fejlesztői env-ben; élesben nem fut |
| **5432** | helyi | PostgreSQL | **Nem** — csak konténerek között; a host `FORWARD_DB_PORT` csak fejlesztői hozzáféréshez |
| **6379** | helyi | Redis | **Nem** — csak konténerek között |

### Kimenő hozzáférés (a szerverről az internet felé)

| Cél | Miért |
|-----|-------|
| `https://onlineszamla.nav.gov.hu` | NAV Online Számla API |
| `https://www.mnb.hu` | MNB árfolyam SOAP API |
| `https://api.simplepay.hu` | SimplePay fizetési API |
| Docker Hub (image letöltés) | `docker compose pull` és build |

### SSL/TLS

Az alkalmazás jelenleg HTTP-n fut (80-as port). **SSL/TLS terminálás nem része
a rendszernek** — ez a rendszergazda feladata (ld. [6. szakasz](#6-ami-nem-része-a-rendszernek)).
Éles üzemben reverse proxy (nginx, Caddy, Traefik) elé kell tenni.

---

## 5. Tárhely és jogosultságok

### A teljes `storage/` fa jogosultságai

A `backend/storage/` könyvtár teljes tartalma a webszerver user (Sail-ben: `sail`,
uid=1000) **kizárólagos tulajdonában** kell legyen. Ez NEM csak a PDF-archívumra
vonatkozik — az érintett alkönyvtárak:

| Alkönyvtár | Mire kell | Következmény, ha root-tulajdonú |
|---|---|---|
| `app/private/documents/` | PDF-ek mentése | backup script csendben nulla fájlt lát |
| `framework/testing/disks/` | teszt-suite fájlrendszer-fake | sail-ként 20 teszt bukik (`Unable to create a directory`) |
| `framework/views/` | Blade template-cache | sail nem tudja felülírni a template-fordítást |
| `framework/cache/` | Laravel alkalmazás-cache | sail nem tud cache-t írni |
| `logs/` | Laravel hibanaplók | hibalog nem tud íródni |

**Fejlesztői (Docker) esetén:**

Minden artisan parancsot `./vendor/bin/sail artisan ...` alakban kell futtatni.
A `docker compose exec laravel.test php artisan ...` (user flag nélkül)
**root-ként fut** → a létrehozott könyvtárak és fájlok `root:root 700/755`
jogosultságot kapnak. Ez a teljes `storage/` fát érinti.

> **Konkrét eset (2026-07-13):** root-ként futtatott tesztek
> `framework/testing/disks/` könyvtárat hoztak létre `root:root 700`-ként.
> Sail-ként futtatva a teszt-suite 20 testtel bukott — miközben root-ként
> 162/162 „zöldet" mutatott. A hamis zöld oka: root belép a `700`-as
> könyvtárba, sail nem tud.

**A teszteket is sail-ként kell futtatni:**

```bash
# Helyes — sail userként fut, valódi eredményt ad
./backend/vendor/bin/sail artisan test

# TILOS — root-ként fut, hamis zöldet adhat és szennyezi a storage/-t
docker compose exec laravel.test php artisan test
```

**Ha root-tulajdonú fájlok keletkeztek a `storage/` alatt** (egyszeri javítás,
Docker-rooton keresztül — interaktív sudo nem kell):

```bash
# A repo gyökeréből futtatva
docker compose exec laravel.test chown -R sail:sail /var/www/html/storage/
docker compose exec laravel.test find /var/www/html/storage/ -type d -exec chmod 755 {} +
docker compose exec laravel.test find /var/www/html/storage/ -type f -exec chmod 644 {} +
```

Igazolás:
```bash
find backend/storage -user root  # → üres eredmény = rendben
```

**Éles (production) esetén:**

A webszerver user (pl. `www-data`, `nginx`, `deploy`) tulajdonolja a `storage/`
teljes fáját. Az artisan parancsok futtatási módja az éles környezet kialakításakor
véglegesítendő (részletek: [`docs/deploy.md`](deploy.md)).

### PDF-archívum (különleges követelmények)

**Hely:** `backend/storage/app/private/documents/`
(a Docker bind mount-on keresztül a hoszon él: `./backend/storage/app/private/documents/`)

A PDF-archívum a fenti általános `storage/` jogosultságon túl két **különleges**
követelménnyel is bír:

- A **backup user** is **OLVASÁSI** jogot igényel (a többi `storage/` alkönyvtárhoz
  a backup usernek nincs szüksége hozzáférésre)
- A könyvtár **gitből ki van zárva** → külső backup **kötelező** élesítés előtt;
  a regenerált PDF nem bit-azonos az eredetivel (jogi következmény)

Részletek és visszaállítási eljárás: [`docs/backup.md`](backup.md).

---

## 6. Ami NEM része a rendszernek

Az alábbiak **a rendszergazda felelőssége** — az ERP nem nyújt beépített megoldást.

| Terület | Állapot | Megjegyzés |
|---------|---------|------------|
| **SSL/TLS** | Nincs beépítve | Reverse proxy szükséges éles env-ben (nginx, Caddy, Traefik) |
| **Backup** | Script megvan, futtatás nyitott | `scripts/backup-pdf.sh` kész, de célgép, ütemezés és retention policy még döntést igényel (ld. [`docs/backup.md`](backup.md)) |
| **Monitoring** | Nincs | Az ERP exit kód 0/nem-0 alapján integrálható monitoringba — de a monitoring rendszer a rendszergazda feladata |
| **Log aggregáció** | Nincs | Laravel naplóit a konténer stdout-ja adja; összegyűjtésük (pl. Loki, ELK) a rendszergazda feladata |
| **Tűzfal / hálózati szegmentáció** | Nincs | A DB és Redis portok alapértelmezésben bind mounton keresztül elérhetők a hoston; éles env-ben le kell zárni |
