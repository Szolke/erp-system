#!/usr/bin/env bash
# verify-backup.sh — Ellenőrzi, hogy a célgépen lévő backup fájlok tartalmilag
# egyeznek a forrással (checksum-alapú összehasonlítás).
#
# CSAK OLVAS ÉS ÖSSZEHASONLÍT — soha nem ír, nem töröl, nem szinkronizál.
# Az rsync --dry-run (--checksum -n) módban fut: tartalom-alapú összehasonlítás,
# a mod-time-t figyelmen kívül hagyja. Egyetlen bit eltérés is eltérésként jelenik meg.
#
# Mit fog meg:
#   - Sérült fájl a célon (bit-rot, részleges írás) → checksumok eltérnek
#   - Forrásban létező fájl hiányzik a célgépről (nem futott le a backup) → eltérés
#
# Mit NEM fog meg:
#   - Célgépen lévő EXTRA fájlok (régebbi backupokból felhalmozódottak) → nem hiba
#   - Adatbázis-konzisztencia (a PDF és a számla DB-bejegyzés összefüggése)
#
# Lassabb, mint a backup-pdf.sh (minden fájl checksumját kiszámítja) — ne futtasd
# minden mentésnél. Javasolt: heti egyszer, vagy manuálisan gyanú esetén.
# Részletek: docs/backup.md — Ellenőrzés gyakorisága.
#
# Használat:
#   ./scripts/verify-backup.sh
#
# Exit kód: 0 = minden fájl egyezik, 1 = eltérés van (vagy hiba)

set -uo pipefail

# ────────────────────────────────────────────────────────────────
# 0. Útvonalak és konfiguráció
# ────────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
CONF_FILE="$SCRIPT_DIR/backup.conf"
SOURCE_DIR="$REPO_ROOT/backend/storage/app/private/documents"

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

SSH_KEY_OPT=""
if [[ -n "${BACKUP_SSH_KEY:-}" ]]; then
    SSH_KEY_OPT="-i $BACKUP_SSH_KEY"
fi
SSH_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=accept-new $SSH_KEY_OPT"
SSH_REMOTE="${BACKUP_REMOTE_USER}@${BACKUP_REMOTE_HOST}"
REMOTE_DEST="$SSH_REMOTE:${BACKUP_REMOTE_PATH}/"

# ────────────────────────────────────────────────────────────────
# 1. Forrás ellenőrzése
# ────────────────────────────────────────────────────────────────

if [[ ! -d "$SOURCE_DIR" ]]; then
    echo "HIBA: Forráskönyvtár nem létezik: $SOURCE_DIR" >&2
    exit 1
fi

SOURCE_COUNT=$(find "$SOURCE_DIR" -type f | wc -l)

if [[ "$SOURCE_COUNT" -eq 0 ]]; then
    echo "HIBA: A forráskönyvtár üres — az ellenőrzés nem értelmes." >&2
    exit 1
fi

# ────────────────────────────────────────────────────────────────
# 2. SSH kapcsolat ellenőrzése
# ────────────────────────────────────────────────────────────────

echo "$(date '+%Y-%m-%d %H:%M:%S') — Checksum-ellenőrzés kezdete"
echo "Forrás: $SOURCE_DIR ($SOURCE_COUNT fájl)"
echo "Cél   : $REMOTE_DEST"
echo ""
echo "SSH kapcsolat tesztelése: $SSH_REMOTE ..."

if ! ssh -o ConnectTimeout=10 $SSH_OPTS "$SSH_REMOTE" "exit 0" 2>&1; then
    echo "HIBA: SSH kapcsolat sikertelen: $SSH_REMOTE" >&2
    exit 1
fi

echo "SSH kapcsolat: OK"

# ────────────────────────────────────────────────────────────────
# 3. rsync dry-run checksum összehasonlítás
# ────────────────────────────────────────────────────────────────
#
# -r  : rekurzív
# -n  : DRY RUN — SOHA nem ír semmit, csak kimutatja az eltéréseket
# --checksum : tartalom-alapú összehasonlítás (md5/adler32 a fájlmérettől függ);
#              a mod-time és a fájlméret önmagában NEM dönt — a tartalom dönt
# --out-format="%n" : csak az eltérő tételek nevét írja ki (egy sor = egy fájl/könyvtár)
#
# Ha egy fájl a célgépen HIÁNYZIK → rsync átvitelre jelöli → megjelenik a listában
# Ha egy fájl checksumja ELTÉR    → rsync átvitelre jelöli → megjelenik a listában
# Ha minden egyezik               → üres kimenet

echo "Checksum-összehasonlítás futtatása (lassabb lehet sok fájlnál)..."

RSYNC_OUTPUT=$(rsync -rn --checksum \
    -e "ssh -o ConnectTimeout=60 $SSH_OPTS" \
    --out-format="%n" \
    "$SOURCE_DIR/" \
    "$REMOTE_DEST") || {
    echo "HIBA: rsync checksum-ellenőrzés sikertelen." >&2
    exit 1
}

# ────────────────────────────────────────────────────────────────
# 4. Eredmény értelmezése
# ────────────────────────────────────────────────────────────────
#
# rsync --out-format="%n" könyvtárokat is listázhat (nevük "/" végű).
# Azokat kiszűrjük — csak a fájl-eltérések érdekesek.

DIFF_FILES=$(printf '%s\n' "$RSYNC_OUTPUT" | grep -v '/$' | grep -v '^$' || true)

DIFF_COUNT=0
if [[ -n "$DIFF_FILES" ]]; then
    DIFF_COUNT=$(printf '%s\n' "$DIFF_FILES" | wc -l)
fi

echo ""
echo "─────────────────────────────────────────────────────────"
echo "Forrás fájlszám     : $SOURCE_COUNT"
echo "Eltérő/hiányzó fájl: $DIFF_COUNT"
echo "─────────────────────────────────────────────────────────"

if [[ -n "$DIFF_FILES" ]]; then
    echo ""
    echo "FIGYELMEZTETÉS — Eltérő vagy hiányzó fájlok a célgépen:" >&2
    printf '%s\n' "$DIFF_FILES" | while IFS= read -r f; do
        printf '  ELTÉR/HIÁNYZIK: %s\n' "$f" >&2
    done
    echo ""
    echo "Teendő: futtasd le a backup-pdf.sh-t az újraszinkronizáláshoz," >&2
    echo "majd futtasd újra ezt a scriptet az eredmény ellenőrzéséhez." >&2
    echo ""
    echo "$(date '+%Y-%m-%d %H:%M:%S') — Ellenőrzés SIKERTELEN"
    exit 1
fi

echo "Minden fájl checksumja egyezik a forráson és a célgépen."
echo "$(date '+%Y-%m-%d %H:%M:%S') — Ellenőrzés SIKERES"
exit 0
