#!/usr/bin/env bash
# dev-up.sh — A lokális dev stack indítása (backend, PostgreSQL, Redis, frontend).
#
# A Windows-oldali tálca-indító (erp-start.ps1, a repón KÍVÜL) így hívja:
#   wsl -d Ubuntu --cd /home/szolke/projects/erp-system -e bash scripts/dev-up.sh
#
# PHP2Shell-védelem (CVE-2026-109618/109619): a script NEM indítja el a stacket
# sérülékeny PHP-build-en — a laravel.test image PHP-verziója legalább 8.5.12
# kell legyen. A guard a futó konténert nem érinti: egy eldobható
# `docker compose run` példányban kérdezi le a verziót, port és függőségek nélkül.
#
# Használat:
#   ./scripts/dev-up.sh
#
# Exit kód: 0 = a stack elindult (a health-check időtúllépése NEM hiba)
#           1 = a Docker nem érhető el
#           2 = a laravel.test PHP-verziója túl régi, vagy nem megállapítható
#           egyéb = a `docker compose up` hibája (a saját kilépési kódjával)

set -euo pipefail

# ────────────────────────────────────────────────────────────────
# 0. Útvonalak és beállítások
# ────────────────────────────────────────────────────────────────
#
# A compose-projekt a repó GYÖKERÉBŐL fut (l. CLAUDE.md), ezért oda lépünk,
# függetlenül attól, honnan hívták a scriptet.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$REPO_ROOT"

MIN_PHP="8.5.12"                        # PHP2Shell-javítás minimum verziója
BACKEND_URL="http://localhost/up"       # Laravel health endpoint
FRONTEND_URL="http://localhost:5174"    # Vite dev szerver
WAIT_TIMEOUT=60                         # mp — legfeljebb ennyit várunk egy URL-re
WAIT_INTERVAL=2                         # mp — két próbálkozás között

# ────────────────────────────────────────────────────────────────
# 1. Segédfüggvény — várakozás egy HTTP-végpontra
# ────────────────────────────────────────────────────────────────
#
# curl -f : a 4xx/5xx válaszkódot is hibának veszi (nem csak a kapcsolódási hibát)
# curl -s : csendes mód, a progress meter nem szennyezi a kimenetet
#
# Visszatérés: 0 = a végpont válaszolt, 1 = időtúllépés.

wait_http() {
    local url="$1"
    local label="$2"
    local deadline=$(( SECONDS + WAIT_TIMEOUT ))

    while (( SECONDS < deadline )); do
        if curl -fs -o /dev/null "$url"; then
            echo "$label: OK ($url)"
            return 0
        fi
        sleep "$WAIT_INTERVAL"
    done

    return 1
}

# ────────────────────────────────────────────────────────────────
# 2. Docker elérhetőség
# ────────────────────────────────────────────────────────────────

if ! docker info >/dev/null 2>&1; then
    echo "HIBA: Docker nem érhető el (fut a Docker Desktop? be van kapcsolva a WSL-integráció?)" >&2
    exit 1
fi

# ────────────────────────────────────────────────────────────────
# 3. PHP2Shell guard — verzióellenőrzés a stack indítása ELŐTT
# ────────────────────────────────────────────────────────────────
#
# A version_compare a PHP saját, szemantikus összehasonlítása: a "8.5.9" < "8.5.12"
# sorrendet helyesen adja (sztringként összehasonlítva nem adná).
#
# A próba kimenetét elnyomjuk, hogy a tálca-indító ne kapjon zajt. Ennek az az
# ára, hogy ha a próba maga nem tud lefutni (hiányzó image, docker-hiba), a verzió
# "ismeretlen" lesz, és a script NEM indítja el a stacket — biztonsági guardnál
# ez a helyes irány: ha nem bizonyítható, hogy javított, akkor nem indul.

echo "PHP-verzió ellenőrzése a laravel.test image-ben (minimum $MIN_PHP) ..."

if ! docker compose run --rm --no-deps -T laravel.test \
        php -r "exit(version_compare(PHP_VERSION, '${MIN_PHP}', '<') ? 1 : 0);" >/dev/null 2>&1; then

    PHP_VER=$(docker compose run --rm --no-deps -T laravel.test \
        php -r 'echo PHP_VERSION;' 2>/dev/null) || PHP_VER=""
    [[ -n "$PHP_VER" ]] || PHP_VER="ismeretlen"

    echo "STOP: a laravel.test PHP-verziója $PHP_VER, minimum $MIN_PHP kell (PHP2Shell)." >&2
    echo "      Futtasd: docker compose build --pull --no-cache laravel.test" >&2
    exit 2
fi

echo "PHP-verzió: OK (>= $MIN_PHP)"

# ────────────────────────────────────────────────────────────────
# 4. A stack indítása
# ────────────────────────────────────────────────────────────────

echo "A stack indítása (docker compose up -d) ..."
docker compose up -d

# ────────────────────────────────────────────────────────────────
# 5. Elérhetőség ellenőrzése
# ────────────────────────────────────────────────────────────────
#
# Az időtúllépés szándékosan NEM állítja meg a scriptet: a frontend első
# indulásakor az `npm install` + Vite indulás percekig is eltarthat, de a
# konténerek ettől még rendben felálltak.

echo ""
echo "Elérhetőség ellenőrzése (legfeljebb $WAIT_TIMEOUT mp szolgáltatásonként) ..."

if ! wait_http "$BACKEND_URL" "Backend "; then
    echo "FIGYELMEZTETÉS: a backend nem válaszolt $WAIT_TIMEOUT mp-en belül: $BACKEND_URL" >&2
    echo "                Napló: docker compose logs laravel.test" >&2
fi

if ! wait_http "$FRONTEND_URL" "Frontend"; then
    echo "FIGYELMEZTETÉS: a frontend nem válaszolt $WAIT_TIMEOUT mp-en belül: $FRONTEND_URL" >&2
    echo "                Az első indulás (npm install + Vite) lassú lehet." >&2
    echo "                Napló: docker compose logs frontend" >&2
fi

# ────────────────────────────────────────────────────────────────
# 6. Állapot
# ────────────────────────────────────────────────────────────────

echo ""
echo "─────────────────────────────────────────────────────────"
docker compose ps
echo "─────────────────────────────────────────────────────────"
echo "Backend : http://localhost"
echo "Frontend: http://localhost:5174"

exit 0
