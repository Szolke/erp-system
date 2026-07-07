# 6. Beállítások

A Beállítások almenü több oldalt tartalmaz; ezeket az oldalsáv Beállítások szekciójában érheted
el. Az egyes oldalak `company.manage` vagy ahhoz hasonló jogosultsághoz kötöttek — ha egy oldal
nem látható az oldalsávban, nincs hozzáférésed.
Vissza: [README.md](../README.md)

---

## Cégbeállítások (Beállítások → Cégbeállítások)

Ez az oldal négy fő szekciót tartalmaz.

### Cégadatok

Az alap céginformációk: név, adószám, EU adószám, cégjegyzékszám, irányítószám, város, cím,
e-mail, telefon, alapdeviza, NAV Online Számla környezet (teszt/éles), számlafejléc-szöveg és
számlalábjegyzet-szöveg. A „Mentés" gomb az összes adatot egyszerre menti.

A NAV-környezet (teszt / éles) mezőt `company.manage` joggal rendelkező felhasználók látják.

### Céglogó feltöltése

A céglogó a kiállított PDF-eken jelenik meg. Feltöltési feltételek: JPEG, PNG, GIF vagy WebP
formátum, maximum 2 MB méret. A feltöltés után a logó azonnal megjelenik az előnézeti területen.
A **Törlés** gomb eltávolítja a logót (a korábban kiállított PDF-ek archiváltakat nem módosítja).

Ez a szekció csak `company.manage` joggal rendelkező felhasználóknak látható.

### Általános beállítások

- **Alapértelmezett deviza** — a céghez beállított devizanem (HUF / EUR / USD). Számlán
  HUF vagy EUR adható meg a kiállításkor; nyugtán HUF, EUR és USD is elérhető.
- **Számla nyelve** — a kiállított PDF-ek feliratainak nyelve (magyar / angol / német).
- **Fizetési határidő (napban)** — az új számlák esedékességét ennyi nappal a kiállítás napja
  utánra állítja be alapértelmezetten.

Ez a szekció csak `company.manage` joggal rendelkező felhasználóknak látható.

### Oldalsáv accent-szín

18 előre definiált szín közül választható az oldalsáv háttérszíne. A választott szín azonnal
életbe lép, és ez a beállítás cégenkénti — több cégnél különböző szín is beállítható.

Ez a szekció csak `company.manage` joggal rendelkező felhasználóknak látható.

### SimplePay hitelesítő adatok

Az online fizetési integráció beállítása devizánként (HUF / EUR / USD). Minden devizához
külön merchant ID és titkos kulcs adható meg. A titkos kulcs titkosítva tárolódik, az
oldalon soha nem olvasható vissza — csak „be van állítva / nincs beállítva" jelzés látható.
A **Sandbox** kapcsoló tesztkörnyezetben tartja a fizetési folyamatot.

---

## Sorszámtartományok (Beállítások → Sorszámtartományok)

A bizonylat-sorszámok prefixe és formátuma itt állítható be. Az alapértelmezett sorozatok
(`SZ`, `NY`, `SZSZT`, `NYSZT`) az új cég létrehozásakor automatikusan jönnek létre.

A sorszámformátum: `PREFIX-ÉÉÉÉHH-000001` (pl. `SZ-202407-000001`). A sorszámozás minden
évkezdetkor automatikusan visszaáll.

Ehhez a `document_series.manage` jogosultság szükséges.

---

## Egyéni mezők (Beállítások → Egyéni mezők)

Partnerekhez és termékekhez saját adatmezők adhatók hozzá. Elérhető típusok: szöveg, szám,
dátum, igen/nem (boolean), választólista (select). A select típusnál soronként add meg a
lehetséges értékeket.

Az egyéni mezők `company.manage` joghoz kötöttek. A definiált mezők megjelennek a partner- és
termékűrlapokon, és az ott felvett adatok a partnerrel/termékkel együtt tárolódnak.

---

## Fordítások (Beállítások → Fordítások)

Az adminisztrátori felületi szövegek (gombok, feliratok, hibaüzenetek stb.) szerkeszthetők ebben
a szerkesztőben három nyelven (HU / EN / DE). A változtatások azonnal érvénybe lépnek a
felhasználói felületen.

Ez a szekció `company.manage` jogosultsághoz kötött.

---

## API tesztelő (Beállítások → API tesztelő)

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
