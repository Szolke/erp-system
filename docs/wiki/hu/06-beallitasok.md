# 6. Beállítások

A Beállítások almenü kategóriákba rendezve tartalmaz több oldalt: **Cégesadatok**,
**Általános**, **Felhasználók**, **Szótárak**. Az egyes oldalak jogosultsághoz — némelyik
kizárólag szuperadminokhoz — kötöttek; ha egy oldal nem látható az oldalsávban, nincs
hozzáférésed hozzá.
Vissza: [README.md](../README.md)

---

## Cégesadatok

> A **Cégek** menüpontról (új cég létrehozása, csak szuperadminoknak) az
> [1. fejezet](01-attekintes-bejelentkezes.md) ír, a Cégváltó szakaszban.

### Cégbeállítások (Beállítások → Cégbeállítások)

Az oldal több szekcióra tagolódik.

#### Cégadatok

Az alap céginformációk: név, adószám, EU adószám, cégjegyzékszám, irányítószám, város, cím,
e-mail, telefon, alapdeviza, NAV Online Számla környezet (teszt/éles), számlafejléc-szöveg és
számlalábjegyzet-szöveg. A „Mentés" gomb az összes adatot egyszerre menti.

A NAV-környezet (teszt / éles) mezőt `company.manage` joggal rendelkező felhasználók látják.

**Értékesítő csoport prefix:** ha az Értékesítő csoportok modul engedélyezve van, az
`Értékesítő csoport prefix` mező is megjelenik. Ez legfeljebb 4 nagybetűs karakterből álló
prefix (pl. `BUD`), amelyet a rendszer automatikusan nagybetűsít. A prefix az értékesítő
csoportok megjelenítőnevének első tagja: `PREFIX_CsoportNév`. A prefixet addig nem lehet
törölni, amíg a cégnek legalább egy értékesítő csoportja van; előbb azokat kell eltávolítani.

#### Céglogó feltöltése

A céglogó a kiállított PDF-eken jelenik meg. Feltöltési feltételek: JPEG, PNG, GIF vagy WebP
formátum, maximum 2 MB méret. A feltöltés után a logó azonnal megjelenik az előnézeti területen.
A **Törlés** gomb eltávolítja a logót (a korábban kiállított PDF-ek archiváltakat nem módosítja).

Ez a szekció csak `company.manage` joggal rendelkező felhasználóknak látható.

#### Általános beállítások

- **Alapértelmezett deviza** — a céghez beállított devizanem (HUF / EUR / USD). Számlán
  HUF vagy EUR adható meg a kiállításkor; nyugtán HUF, EUR és USD is elérhető.
- **Számla nyelve** — a kiállított PDF-ek feliratainak nyelve (magyar / angol / német).
- **Fizetési határidő (napban)** — az új számlák esedékességét ennyi nappal a kiállítás napja
  utánra állítja be alapértelmezetten.

Ez a szekció csak `company.manage` joggal rendelkező felhasználóknak látható.

#### Oldalsáv accent-szín

18 előre definiált szín közül választható az oldalsáv háttérszíne. A választott szín azonnal
életbe lép, és ez a beállítás cégenkénti — több cégnél különböző szín is beállítható.

Ez a szekció csak `company.manage` joggal rendelkező felhasználóknak látható.

#### SimplePay hitelesítő adatok

Az online fizetési integráció beállítása devizánként (HUF / EUR / USD). Minden devizához
külön merchant ID és titkos kulcs adható meg. A titkos kulcs titkosítva tárolódik, az
oldalon soha nem olvasható vissza — csak „be van állítva / nincs beállítva" jelzés látható.
A **Sandbox** kapcsoló tesztkörnyezetben tartja a fizetési folyamatot.

Ez a szekció csak `simplepay.manage` joggal rendelkező felhasználóknak látható, és csak ha a
SimplePay modul engedélyezve van.

#### NAV Online Számla hitelesítő adatok

A NAV Online Számla 3.0 API-hoz szükséges technikai felhasználói adatok beállítása. Két
környezet (teszt / éles) kezelése egymástól független hitelesítőkkel.

Az aktív környezet (`NAV Online Számla környezet` a Cégadatok szekcióban) határozza meg, melyik
credential-készletet használja a rendszer az automatikus számlariportáláshoz.

**Figyelmeztető sáv:** ha a NAV modul engedélyezve van, de az aktív környezethez nincs
`is_active=true` credential beállítva, egy sárga figyelmeztető sáv jelenik meg — ilyenkor a
NAV-küldési job nem indítja el a riportálást, és figyelmeztetést ír a naplóba.

**Hitelesítő adatok:**
- Technikai felhasználónév
- Technikai felhasználó jelszava
- XML aláírókulcs
- XML cserekulcs

Minden titkosmező titkosítva tárolódik, és soha nem olvasható vissza a felületen — mentéskor
az üresen hagyott mezők a meglévő értéket tartják meg.

**Környezetváltás:** az aktív NAV-környezet (teszt → éles) váltása megerősítő modallal történik,
és csak akkor lehetséges, ha a célkörnyezethez már létezik aktív credential.

Ez a szekció csak `invoice.send_nav` joggal rendelkező felhasználóknak látható, és csak ha a
NAV Online Számla modul engedélyezve van.

### Sorszámtartományok (Beállítások → Sorszámtartományok)

A bizonylat-sorszámok prefixe és formátuma itt állítható be. Az alapértelmezett sorozatok
(`SZ`, `NY`, `SZSZT`, `NYSZT`) az új cég létrehozásakor automatikusan jönnek létre.

A sorszámformátum: `PREFIX-ÉÉÉÉHH-000001` (pl. `SZ-202407-000001`). A sorszámozás minden
évkezdetkor automatikusan visszaáll.

Ehhez a `document_series.manage` jogosultság szükséges.

---

## Általános

### Modulkezelő (Beállítások → Modulok)

Ez az oldal kizárólag **szuperadminok** számára érhető el.

A rendszer moduláris: egyes funkciók (NAV Online Számla, SimplePay online fizetés,
Értékesítő csoportok) modul-alapon kapcsolhatók be és ki cégenként. Az alapmoduloknál
(`Mindig aktív` jelzés) — számlázás, nyugta, partnerek, termékek — ez a lehetőség nem
áll fenn, ezek mindig aktívak.

**Modul engedélyezése / letiltása:**
Minden modul-kártyán egy kapcsoló jelenik meg. Ha egy modulnak van függősége (pl. NAV Online
Számla igényli a számlázás modult), a rendszer ellenőrzi, hogy a szükséges modul aktív-e —
ha nem, a bekapcsolás 422-es hibával meghiúsul, és a hiányzó modul nevével jelez.
Fordítva: ha egy aktív modul függőként hivatkozik egy másikra, az utóbbi csak akkor kapcsolható
ki, ha az előbbit előbb letiltják.

**„Konfigurálás →" link:**
Egyes engedélyezett moduloknál (pl. NAV Online Számla, SimplePay, Értékesítő csoportok) egy
„Konfigurálás →" link jelenik meg a kártya alján. Ez a modul saját beállítási oldalára, illetve
a Cégbeállítások megfelelő szekciójára navigál.

### NAV napló (Beállítások → Általános → NAV napló)

Minden NAV-beküldési kísérlet — a számla feladása ÉS a NAV-verdikt utólagos lekérdezése is —
naplózódik. Ez a lista mutatja meg, hogy van-e bárhol probléma: alapértelmezetten a hibás,
elutasított vagy 24 órán belül verdikt nélkül maradt (beavatkozást igénylő) számlákra szűrve
jelenik meg. Egy sor egy érintett számlát jelent, a kísérletek számával együtt — nem minden
egyes próbálkozás külön sorban.

Soronként megnyitható a legutolsó kísérlet részletei, ahol a NAV-nak elküldött és a NAV-tól
kapott nyers adat is megtekinthető (csak ekkor töltődik be, nem a lista részeként). A számla
saját részletező oldalán is megjelenik egy „NAV beküldési előzmények" panel, ami az adott
számla teljes, időrendi történetét mutatja.

Ez a lista/panel csak `nav.log.view` joggal rendelkező felhasználóknak látható — ez a jog
KÜLÖN a NAV-hitelesítők kezeléséhez szükséges `invoice.send_nav`-tól, mert a napló nyers
NAV-kommunikációt tartalmaz, érzékenyebb, mint maga a számla megtekintése.

### Egyéni mezők (Beállítások → Egyéni mezők)

Partnerekhez és termékekhez saját adatmezők adhatók hozzá. Elérhető típusok: szöveg, szám,
dátum, igen/nem (boolean), választólista (select). A select típusnál soronként add meg a
lehetséges értékeket.

Az egyéni mezők `company.manage` joghoz kötöttek. A definiált mezők megjelennek a partner- és
termékűrlapokon, és az ott felvett adatok a partnerrel/termékkel együtt tárolódnak.

### API tesztelő (Beállítások → API tesztelő)

Interaktív felület az ERP saját REST API-jának böngészéséhez és teszteléséhez. A bal panelen
csoportonként összecsukható listában láthatók az elérhető endpointok (metódusbadge + útvonal);
jobb oldalon az endpoint részletei jelennek meg: paramétertábla, request body schema, majd a
„Kérés küldése" kártya.

**Kérés küldése:**
- A `{invoice}`, `{partner}` stb. útvonal-paramétereket külön beviteli mezőkben kell megadni.
- Lekérdezési paraméterek (pl. `page`, `per_page`) szintén kitölthetők.
- POST/PUT/PATCH metódusoknál JSON body textarea jelenik meg, a schema alapján előkitöltve.
- A „Küldés" gombra kattintva a válasz (HTTP státusz, átfutási idő, JSON body) a jobb panel
  alján jelenik meg.

**Visszafordíthatatlan műveletek védelme:**  
Sztornózás (`/cancel`), SimplePay visszatérítés (`/refund`), PDF újragenerálás
(`/regenerate-pdf`) és törlés (DELETE) esetén az egyszerű megerősítés helyett egy piros keretű
figyelmeztető modal jelenik meg, amely tartalmazza az aktív cég nevét. A „Küldés" gomb csak egy
„Megértettem, ez a művelet visszafordíthatatlan" jelölőnégyzet bejelölése után válik aktívvá.

Ez az oldal `api_tester.use` jogosultsághoz kötött.

---

## Felhasználók

A **Felhasználók** és **Csoportok** oldalak itt érhetők el; a jogosultság-kezelés (RBAC)
teljes leírását — szerepkörök, csoporttagság, egyedi felülbírálás — a
[7. fejezet](07-felhasznalok-jogosultsagok.md) tartalmazza.

---

## Szótárak

### Országok (Beállítások → Országok)

Ez az oldal kizárólag **szuperadminok** számára érhető el. Itt választható ki, mely országok
jelenjenek meg a rendszer egészében választható opcióként — a lista jelölőnégyzetekkel
szabályozható, kereséssel szűrhető, majd egy közös **Mentés** gombbal menthető.

> Ami itt le van tiltva, az a partner-űrlap „Számlázási ország" mezőjében sem választható —
> a két lista össze van kötve. Egy már mentett, azóta letiltott ország a partner adatlapján
> nem tűnik el, csak új értékként nem választható újra.

### Munkakörök (Beállítások → Munkakörök)

Szabadon elnevezhető munkakörök (pl. „Recepciós", „Könyvelő") hozhatók létre és rendelhetők
a felhasználókhoz — a mező tisztán tájékoztató jellegű, nincs jogosultsághoz kötve. Egy
munkakör inaktívvá tehető: ilyenkor a meglévő hozzárendeléseket nem érinti, csak új
kiválasztásra nem lesz elérhető. Szuperadminok „globális" munkakört is létrehozhatnak, amely
minden céget érint, nem csak a sajátjukat.

A felhasználó-űrlapon (felhasználó létrehozásakor és a felhasználó adatlapján) jelenik meg a
„Munkakör" mező, ahonnan a listából választható.

### Eszközök és eszköztípusok (Eszközök / Beállítások → Eszköztípusok)

Ez a funkció csak akkor érhető el, ha az **Eszközök** modul engedélyezve van. Az
**Eszközök** menüpont (oldalsáv, felül) a cég fizikai eszközeit (pl. POS terminálok,
telefonok) tartja nyilván — `asset.view` jogosultság szükséges a megtekintéshez.

**Eszköz felvétele** (`asset.create` jog): meg kell adni a gyári számot és a
eszköztípust; az IMEI opcionális. **A nevet a rendszer generálja** a típus kódja és
egy sorszám alapján (pl. `DEMO_TEYA_00001`) — ez a mező a felvételkor nem
szerkeszthető, csak mentés után jelenik meg.

**Szerkesztés** (`asset.edit` jog): csak az állapot (aktív / kiadva / szervizben /
selejtezve), a gyári szám és az IMEI módosítható. A név és az eszköztípus a
létrehozás után véglegesen rögzített.

**Törlés** (`asset.delete` jog): az eszköz véglegesen törlődik.

Az **Eszköztípusok** oldal (Beállítások → Eszköztípusok, `asset.view` jog) mutatja a
globális (minden cég számára elérhető, pl. Mobiltelefon, Teya POS terminál,
Nyomtató) és a saját cég által létrehozott típusokat. **Új típus hozzáadása**
(`asset.create` jog) mindig a saját céghez kerül — más cégek nem látják.

### Értékesítő csoportok (Beállítások → Értékesítő csoportok)

Ez az oldal csak akkor érhető el, ha az **Értékesítő csoportok** modul engedélyezve van, és
`sales_group.view` jogosultság szükséges a megtekintéshez.

Az értékesítő csoportok az érintett céghez kötöttek — más cég csoportjai nem láthatók. Minden
csoport megjelenítőneve a cég prefixéből és a csoport nevéből áll össze:
`PREFIX_CsoportNév` (pl. `BUD_Észak`).

**Új csoport hozzáadása** (`sales_group.create` jog):
A névnek egyedinek kell lennie a cégen belül (kis- és nagybetű-független). Ha a céghez nincs
prefix beállítva, a rendszer 422-es hibaüzenettel jelzi, hogy előbb a Cégbeállításokban be
kell állítani a prefixet.

**Szerkesztés** (`sales_group.edit` jog): csak a csoport neve módosítható; a megjelenítőnév
automatikusan frissül.

**Törlés** (`sales_group.delete` jog): a csoport véglegesen törlődik.

### Fordítások (Beállítások → Fordítások)

Az adminisztrátori felületi szövegek (gombok, feliratok, hibaüzenetek stb.) szerkeszthetők ebben
a szerkesztőben három nyelven (HU / EN / DE). A változtatások azonnal érvénybe lépnek a
felhasználói felületen.

Ez a szekció `company.manage` jogosultsághoz kötött.
