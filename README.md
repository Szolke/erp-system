# ERP-rendszer (kisvállalati)

Kisvállalati ERP-szerű webalkalmazás: számlázás, törzsadatok, jogosultságkezelés,
NAV Online Számla 3.0 integráció, SimplePay fizetés.

## Technológiai stack

- **Backend**: PHP 8.5 / Laravel 13, Laravel Sail (Docker)
- **Adatbázis**: PostgreSQL 18
- **Frontend**: React (Vite), SPA
- **Cache / Queue**: Redis
- **Auth**: Laravel Sanctum (API token alapú)

## Mappastruktúra
erp-system/
├── backend/          Laravel API (REST)
├── frontend/          React SPA (Vite)
├── compose.yaml        Közös Docker Compose (backend + frontend + pgsql + redis)
└── .env -> backend/.env  Symlink, hogy a compose.yaml is lássa az env változókat
## Fejlesztői környezet előfeltételei

- Windows + WSL2 (Ubuntu disztribúció)
- Docker Desktop, WSL2 backend-del, WSL integráció bekapcsolva az Ubuntu disztrohoz
- VS Code, WSL extension-nel megnyitva (`\\wsl$\Ubuntu\home\<user>\projects\erp-system`)

**Fontos**: a projekt a WSL2 natív Linux fájlrendszerén kell, hogy legyen
(`/home/<user>/projects/erp-system`), NE a Windows `/mnt/c/...` vagy `D:\...` alatt,
mert az drasztikusan lassítja a fájlműveleteket és jogosultsági problémákat okozhat.

## Indítás

```bash
cd ~/projects/erp-system
docker compose up -d
```

Első indításkor a Laravel image build-elése és a frontend `npm install`-ja eltarthat
egy-két percig.

Ellenőrzés:

```bash
docker compose ps
```

Mind a 4 szolgáltatásnak (`laravel.test`, `pgsql`, `redis`, `frontend`) "Up" / "healthy"
állapotban kell lennie.

## Elérhetőségek

| Szolgáltatás        | URL                     |
|----------------------|--------------------------|
| Backend (Laravel)    | http://localhost        |
| Frontend (React/Vite)| http://localhost:5174   |
| PostgreSQL           | localhost:5432           |
| Redis                | localhost:6379           |

## Gyakori parancsok

```bash
# Artisan parancsok futtatása
docker compose exec laravel.test php artisan migrate
docker compose exec laravel.test php artisan migrate:status
docker compose exec laravel.test php artisan tinker

# Composer csomag hozzáadása
docker compose exec laravel.test composer require <csomag>

# Logok megtekintése
docker compose logs -f laravel.test
docker compose logs -f pgsql

# Leállítás
docker compose down

# Teljes újraindítás (hálózati problémák esetén)
docker compose down
docker compose up -d
```

## Hibakeresés

Ha a backend nem éri el a `pgsql` host-ot ("could not translate host name"),
ellenőrizd, hogy minden konténer ugyanazon a Docker hálózaton van-e:

```bash
docker network inspect erp-system_sail
```

Ha valamelyik szolgáltatás hiányzik a `Containers` listából, csináljunk teljes
letakarítást és tiszta újraindítást:

```bash
docker compose down
docker compose up -d
```

## Funkcionális modulok (tervezett)

1. Multi-company kezelés (egy adatbázis, `company_id` szűréssel minden táblában)
2. Felhasználók és csoportok, RBAC jogosultságkezelés
3. Termék- és szolgáltatástörzs
4. Partnertörzs (vevők/szállítók)
5. Számlázás (többdevizás, NAV Online Számla 3.0, sztornózás)
6. Nyugta kiállítás
7. Fizetési módok (készpénz, bankkártya, átutalás, SimplePay)
8. SimplePay integráció (aszinkron IPN feldolgozás)
9. Audit log

## Fejlesztési elvek

- Minden lekérdezésnél kötelező a `company_id` szerinti szűrés
- NAV és SimplePay hívások mindig aszinkron (Laravel Queue)
- API-first: backend tiszta REST API, frontend React SPA-ként fogyasztja
- Moduláris kódbázis, könnyen bővíthető (további fizetési szolgáltatók, riportok, raktárkezelés)
