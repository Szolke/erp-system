# PDF-archívum biztonsági mentés

> **Egy igazságforrás:** a funkció-állapot és a nyitott pontok a
> [`docs/progress.md`](progress.md) Nyitott pont #7 alatt követhetők.

---

## Mit ment és miért kritikus

A `backend/storage/app/private/documents/` könyvtár tartalmaz minden kiállított
számla- és nyugta-PDF-et, beleértve a `.superseded-{timestamp}.pdf` archív
verziókat is (kontrollált újrageneráláskor a régi kanonikus PDF ide kerül).

**Elvesztésük visszafordíthatatlan jogi következménnyel jár:**
- A PDF-ek tartalom szerint regenerálhatók, de NEM bit-azonosan (DomPDF a
  generálás időpontját beágyazza; sablon, logó, fordítás időközben változhat).
- Az eredeti kiállított dokumentum visszaállíthatatlan veszteség.
- A PostgreSQL adatbázis külön Docker named volume-on él — az adatok túlélik a
  konténer újraindítását. A PDF-ek NEM — azok bind mount-on, a hoszon vannak.

**Mit tartalmaz a backup:**

```
documents/
  {company_id}/
    {YYYY}/
      {MM}/
        {DD}/
          {invoice_number}.pdf            ← kanonikus PDF
          .superseded-{timestamp}.pdf     ← archívum (újrageneráláskor keletkezik)
```

---

## Előfeltételek

### 1. SSH kulcs a célgépre

A backup script jelszó nélkül fut (BatchMode). A forrás gépen (`szolke@erp-host`)
létre kell hozni egy dedikált backup SSH-kulcspárt, és a nyilvános kulcsot fel kell
másolni a célgépre:

```bash
# Kulcspár generálása (passphrase nélkül — automatizált futtatáshoz)
ssh-keygen -t ed25519 -f ~/.ssh/erp_backup_key -N "" -C "erp-pdf-backup"

# Nyilvános kulcs telepítése a célgépre
ssh-copy-id -i ~/.ssh/erp_backup_key.pub szolke@192.168.1.100
```

### 2. Célkönyvtár létrehozása a célgépen

```bash
ssh szolke@192.168.1.100 "mkdir -p /backup/erp/documents"
```

### 3. Első kapcsolat jóváhagyása

Az `StrictHostKeyChecking=accept-new` beállítás az első kapcsolatnál automatikusan
elfogadja a célgép host key-jét, és eltárolja a `~/.ssh/known_hosts`-ban. Kézi
jóváhagyáshoz:

```bash
ssh -i ~/.ssh/erp_backup_key szolke@192.168.1.100 "exit 0"
# "Are you sure you want to continue connecting (yes/no)?" → yes
```

---

## Konfiguráció

```bash
# Sablon másolása (egyszer kell)
cp scripts/backup.conf.example scripts/backup.conf
```

`scripts/backup.conf` tartalma (gitignore-olt — SOHA ne commitold):

```bash
BACKUP_REMOTE_USER="szolke"          # SSH user a célgépen
BACKUP_REMOTE_HOST="192.168.1.100"   # célgép IP-je vagy hostname-je
BACKUP_REMOTE_PATH="/backup/erp/documents"  # célkönyvtár
BACKUP_SSH_KEY="/home/szolke/.ssh/erp_backup_key"  # SSH kulcs (üresen = default)
```

---

## Futtatás

```bash
cd /home/szolke/projects/erp-system
bash scripts/backup-pdf.sh
```

Sikeres futás esetén a script:
1. Kiírja a forrás fájlszámát
2. Ellenőrzi az SSH kapcsolatot
3. Lefuttatja az rsync-et (`-av --stats` — minden fájl neve és végső statisztika látszik)
4. Összehasonlítja a forrás és cél fájlszámát
5. `exit 0`-val tér vissza

Hiba esetén nem-0 exit kóddal áll le, és a hiba oka `stderr`-re kerül.

---

## Tartalmi ellenőrzés (verify-backup.sh)

A `backup-pdf.sh` fájlszám-összehasonlítása **nem igazolja a tartalmat**: egy sérült PDF
(bit-rot, részleges írás) ugyanúgy „1 fájl" a célgépen, mint az ép eredeti. A fájlszám
stimmel, a backup csendben elromlott.

A `verify-backup.sh` ezt az esetet fogja meg: checksum-alapú összehasonlítást végez
rsync dry-run módban — egyetlen tényleges fájlt sem ír, nem töröl, nem szinkronizál.

```bash
cd /home/szolke/projects/erp-system
bash scripts/verify-backup.sh
```

**Mit fog meg:**
- Sérült fájl a célgépen (más checksum mint a forráson)
- Forrásban létező fájl, ami hiányzik a célgépen (incomplete backup)

**Mit NEM fog meg:**
- Célgépen lévő extra fájlok (régebbi backupokból felhalmozódottak) — ez nem hiba
- Adatbázis-konzisztencia (a PDF tartalmát nem veti össze a DB-bejegyzéssel)

**Milyen gyakran futtasd:** lassabb, mint a mentés (minden fájl checksumját kiszámítja),
ezért nem minden mentésnél szükséges. Javasolt: heti egyszer, vagy gyanú esetén
(pl. diszkhiba volt, rsync hibával állt le). A pontos ütemezés nyitott — ld. alább.

**Exit kód:** 0 = minden egyezik, 1 = eltérés van (a különböző fájlok listájával együtt).

---

## Visszaállítás

> Ez a legfontosabb rész — egy backup, amiből nem tudsz visszaállítani, nem backup.

### ⚠ Kötelező teendő az első beállítás után

**Egy backup, amiből még SOHA nem állítottál vissza, csak egy feltételezés.**

Az első működő backup után végezz el egy **próba-visszaállítást** egy teszt-könyvtárba,
és nyisd meg az egyik visszaállított PDF-et:

```bash
# Próba-visszaállítás /tmp/erp-restore-test/-be
mkdir -p /tmp/erp-restore-test
rsync -av \
    -e "ssh -i ~/.ssh/erp_backup_key" \
    szolke@192.168.1.100:/backup/erp/documents/ \
    /tmp/erp-restore-test/

# Ellenőrzés: van-e fájl, megnyitható-e
find /tmp/erp-restore-test -name "*.pdf" | head -3
# Nyisd meg az egyiket: xdg-open /tmp/erp-restore-test/.../SZ-202607-000001.pdf

# Takarítás
rm -rf /tmp/erp-restore-test
```

Ez a lépés addig **blokkolja** az éles backup-stratégia lezárását, amíg nincs elvégezve.

---

### A. Teljes archívum visszaállítása (pl. diszkhiba után)

```bash
# Forrás gépen (ahol az ERP fut)
# 1. Ellenőrizd, hogy a célkönyvtár elérhető és nem üres
ssh -i ~/.ssh/erp_backup_key szolke@192.168.1.100 \
    "find /backup/erp/documents -type f | wc -l"
# Elvárt: > 0

# 2. rsync fordított irányban (célgép → forrás gép)
rsync -av \
    -e "ssh -i ~/.ssh/erp_backup_key" \
    szolke@192.168.1.100:/backup/erp/documents/ \
    /home/szolke/projects/erp-system/backend/storage/app/private/documents/

# 3. Jogosultságok javítása (szolke:szolke 755/644)
find backend/storage/app/private/documents -type d -exec chmod 755 {} \;
find backend/storage/app/private/documents -type f -exec chmod 644 {} \;
# Tulajdonos rendszerint már helyes (szolke:szolke), de ellenőrizd:
ls -la backend/storage/app/private/documents/ | head -5
```

### B. Egyetlen cég PDF-jeinek visszaállítása

```bash
# Csak a {company_id} könyvtárat állítja vissza
rsync -av \
    -e "ssh -i ~/.ssh/erp_backup_key" \
    szolke@192.168.1.100:/backup/erp/documents/{company_id}/ \
    /home/szolke/projects/erp-system/backend/storage/app/private/documents/{company_id}/
```

### C. Egyetlen fájl visszaállítása

```bash
# A fájl azonosítása az adatbázisból (invoice_number alapján)
# Elérési út: documents/{company_id}/{YYYY}/{MM}/{DD}/{invoice_number}.pdf

scp -i ~/.ssh/erp_backup_key \
    szolke@192.168.1.100:/backup/erp/documents/43/2026/07/10/SZ-202607-000042.pdf \
    /home/szolke/projects/erp-system/backend/storage/app/private/documents/43/2026/07/10/
```

### Visszaállítás utáni ellenőrzés

```bash
# Fájlszám egyezés
SOURCE=$(find backend/storage/app/private/documents -type f | wc -l)
REMOTE=$(ssh -i ~/.ssh/erp_backup_key szolke@192.168.1.100 \
    "find /backup/erp/documents -type f | wc -l")
echo "Forrás: $SOURCE, Backup: $REMOTE"

# PDF letöltési teszt az ERP-ből (böngészőben vagy curl-lel)
# Ha egy adott számla PDF-je nem nyílik meg, a regenerate-pdf végpont
# újra előállíthatja (nem bit-azonos, de tartalmilag helyes):
#   POST /api/invoices/{id}/regenerate-pdf
```

---

## Nyitott kérdések (TBD)

| Kérdés | Állapot |
|--------|---------|
| **Ütemezés** (cron / systemd timer) | Nyitott — a script kézzel futtatható, az ütemezésről külön döntünk |
| **Retention policy** (meddig őrizze a célgép a fájlokat) | Nyitott — jogi utánanézés folyamatban; a script szándékosan NEM töröl |
| **Monitoring / riasztás** (ha a backup nem fut le) | Nyitott — exit kód 0/nem-0 alapján integrálható, ha van monitoring |
| **Checksum-ellenőrzés gyakorisága** (verify-backup.sh) | Nyitott — javasolt: heti, de az ütemezés döntést igényel |
| **Titkosítás** (SSH in-transit titkosít; at-rest a célgépen nem) | Elvárás szerint meghatározandó |

---

## rsync működése — választott opciók

| Opció | Bekapcsolva | Indoklás |
|-------|-------------|----------|
| `-a` (archive) | ✓ | Megőrzi jogosultságokat, időbélyegeket, rekurzív |
| `-v` (verbose) | ✓ | Minden fájl neve látszik — audit trail a logban |
| `--stats` | ✓ | Összesítő statisztika (fájlszám, méret, sebesség) |
| `--delete` | ✗ | Veszélyes: sérült/üres forrás esetén töröl a célon |
| `-z` (compress) | ✗ | PDF belső DEFLATE-et használ, újratömörítés nem segít |
| `--checksum` | ✗ | Lassú és felesleges: PDF-ek kiállítás után nem változnak |
