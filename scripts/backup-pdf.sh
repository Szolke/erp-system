#!/usr/bin/env bash
# backup-pdf.sh — ERP számla-PDF archívum biztonsági mentése rsync/SSH segítségével.
#
# Retention policy TBD — a script szándékosan NEM töröl fájlokat a célgépen.
# Az egyetlen irány: forrás → cél (egyirányú másolás, felhalmozó logika).
#
# Használat:
#   ./scripts/backup-pdf.sh
#
# Konfiguráció: scripts/backup.conf (gitignore-olt, a repoban NEM szerepel)
# Sablon:       scripts/backup.conf.example
# Dokumentáció: docs/backup.md

set -uo pipefail

# ────────────────────────────────────────────────────────────────
# 0. Útvonalak
# ────────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
CONF_FILE="$SCRIPT_DIR/backup.conf"
SOURCE_DIR="$REPO_ROOT/backend/storage/app/private/documents"

# ────────────────────────────────────────────────────────────────
# 1. Konfiguráció betöltése
# ────────────────────────────────────────────────────────────────

if [[ ! -f "$CONF_FILE" ]]; then
    echo "HIBA: Konfigurációs fájl nem található: $CONF_FILE" >&2
    echo "      Másold a scripts/backup.conf.example fájlt backup.conf-ra, és töltsd ki." >&2
    exit 1
fi

# shellcheck source=/dev/null
source "$CONF_FILE"

for VAR in BACKUP_REMOTE_USER BACKUP_REMOTE_HOST BACKUP_REMOTE_PATH; do
    if [[ -z "${!VAR:-}" ]]; then
        echo "HIBA: $VAR nincs beállítva a $CONF_FILE fájlban." >&2
        exit 1
    fi
done

# SSH kulcs opcionális — ha nincs megadva, az SSH az alapértelmezett kulcsot használja
SSH_KEY_OPT=""
if [[ -n "${BACKUP_SSH_KEY:-}" ]]; then
    SSH_KEY_OPT="-i $BACKUP_SSH_KEY"
fi

# Közös SSH kapcsolati opciók (stringként, mert rsync -e is ezt kapja)
SSH_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=accept-new $SSH_KEY_OPT"
SSH_REMOTE="${BACKUP_REMOTE_USER}@${BACKUP_REMOTE_HOST}"

# ────────────────────────────────────────────────────────────────
# 2. Forrás ellenőrzése — létezik és nem üres
# ────────────────────────────────────────────────────────────────

if [[ ! -d "$SOURCE_DIR" ]]; then
    echo "HIBA: Forráskönyvtár nem létezik: $SOURCE_DIR" >&2
    exit 1
fi

SOURCE_COUNT=$(find "$SOURCE_DIR" -type f | wc -l)

if [[ "$SOURCE_COUNT" -eq 0 ]]; then
    echo "HIBA: A forráskönyvtár üres: $SOURCE_DIR" >&2
    echo "      Üres forrásból való mentés adatvesztést jelezhet — mentés megszakítva." >&2
    exit 1
fi

echo "$(date '+%Y-%m-%d %H:%M:%S') — Mentés kezdete"
echo "Forrás: $SOURCE_DIR ($SOURCE_COUNT fájl)"

# ────────────────────────────────────────────────────────────────
# 3. SSH kapcsolat ellenőrzése
# ────────────────────────────────────────────────────────────────

echo "SSH kapcsolat tesztelése: $SSH_REMOTE ..."

if ! ssh -o ConnectTimeout=10 $SSH_OPTS "$SSH_REMOTE" "exit 0" 2>&1; then
    echo "HIBA: SSH kapcsolat sikertelen: $SSH_REMOTE" >&2
    echo "      Ellenőrizd: a célgép elérhető-e, az SSH kulcs telepítve van-e." >&2
    exit 1
fi

echo "SSH kapcsolat: OK"

# ────────────────────────────────────────────────────────────────
# 4. rsync futtatása
# ────────────────────────────────────────────────────────────────
#
# Opciók indoklása:
#   -a  archive mód: rekurzív, megőrzi jogosultságokat, időbélyegeket, tulajdonost
#   -v  verbose: minden átmásolt fájl megjelenik a logban
#   --stats: összesítő statisztika (fájlszám, átvitt méret, sebesség)
#
# --delete SZÁNDÉKOSAN HIÁNYZIK: ha a forrás sérülne vagy üres lenne, a --delete
# a célgépen is megsemmisítené az archívumot. Cél: MEGŐRZÉS, nem tükrözés.
#
# -z (tömörítés) SZÁNDÉKOSAN HIÁNYZIK: a PDF-ek belső DEFLATE tömörítés miatt
# újratömörítés nem hoz méretcsökkentést, csak CPU-terhelést ad.
#
# --checksum (tartalom-alapú összehasonlítás) SZÁNDÉKOSAN HIÁNYZIK: a default
# mod-time+size összehasonlítás elegendő és sokkal gyorsabb. PDF-ek nem változnak
# a kiállítás után, a .superseded-* archívumok sem módosulnak.

REMOTE_DEST="$SSH_REMOTE:${BACKUP_REMOTE_PATH}/"

echo "rsync: $SOURCE_DIR → $REMOTE_DEST"

if ! rsync -av --stats \
    -e "ssh -o ConnectTimeout=30 $SSH_OPTS" \
    "$SOURCE_DIR/" \
    "$REMOTE_DEST"; then
    echo "HIBA: rsync sikertelen." >&2
    exit 1
fi

echo "$(date '+%Y-%m-%d %H:%M:%S') — rsync befejezve"

# ────────────────────────────────────────────────────────────────
# 5. Forrás ↔ cél fájlszám összehasonlítása
# ────────────────────────────────────────────────────────────────
#
# A célgépen >= SOURCE_COUNT fájl várható (felhalmozó logika — régebbi futásokból
# maradhatnak fájlok, amelyek azóta a forrásból törlésre kerültek).
# Ha a cél KEVESEBB fájlt tartalmaz, mint a forrás, az rsync nem végzett el mindent.

DEST_COUNT=$(ssh -o ConnectTimeout=10 $SSH_OPTS "$SSH_REMOTE" \
    "find '${BACKUP_REMOTE_PATH}' -type f 2>/dev/null | wc -l") || {
    echo "FIGYELMEZTETÉS: Célgép fájlszáma nem kérdezhető le — az összehasonlítás kihagyva." >&2
    DEST_COUNT="?"
}

echo ""
echo "─────────────────────────────────────────────────────────"
echo "Forrás fájlszám : $SOURCE_COUNT"
echo "Cél fájlszám    : $DEST_COUNT"
echo "─────────────────────────────────────────────────────────"

if [[ "$DEST_COUNT" != "?" ]] && [[ "$DEST_COUNT" -lt "$SOURCE_COUNT" ]]; then
    echo "FIGYELMEZTETÉS: A célgépen kevesebb fájl van ($DEST_COUNT), mint a forrásban ($SOURCE_COUNT)!" >&2
    echo "                Ellenőrizd a rsync outputot és a célkönyvtár jogosultságait." >&2
    exit 1
fi

echo "Mentés SIKERES."
echo "$(date '+%Y-%m-%d %H:%M:%S') — Kész"
exit 0
