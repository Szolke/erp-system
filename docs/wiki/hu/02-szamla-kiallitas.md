# 2. Számla kiállítása

A bizonylatok listájáról (Bizonylatok → **Új számla** gomb) indítható a kiállítási folyamat.
Vissza: [README.md](../README.md)

---

## Az űrlap mezői

**Fejléc-adatok:**

- **Partner** — kötelező; válassz az előre felvett partnerek közül. (Partnereket a
  Partnerek menüpontban lehet felvenni, mielőtt számlát állítanál ki rájuk.)
- **Fizetési mód** — kötelező; az első mód automatikusan előre ki van választva.
- **Kiállítás dátuma** — alapértelmezetten a mai nap.
- **Teljesítési dátum** — kötelező; alapértelmezetten a mai nap.
- **Esedékesség** — kötelező; alapértelmezetten 30 nappal a mai nap után.
- **Deviza** — HUF vagy EUR.
- **Megjegyzés** — opcionális szabad szöveges mező; a PDF-en jelenik meg.

---

## Tételsorok

Minden sor egy terméket vagy szolgáltatást jelöl. Legalább egy sor szükséges, és minden sorban
kötelező terméket kiválasztani.

A soron belüli mezők:

- **Termék / leírás** — a ProductComboBoxból válassz terméket; automatikusan kitölti az
  egységárat, ÁFÁ-t és mértékegységet. Kiválasztás után a leírás és az egységár még
  szerkeszthető marad.
- **Mértékegység** — szabad szöveges mező (pl. db, óra, kg).
- **Mennyiség** — szám, 0,001 pontossággal.
- **Egységár** — nettó egységár.
- **ÁFA** — a terméknél beállított kulcs előre kiválasztott; módosítható.
- **Kedvezmény %** — opcionális; ha megadsz értéket, a sor végösszegéből levonódik.

Sorok hozzáadása: **+ Tétel hozzáadása** gomb. Sor törlése: a sor jobb szélén lévő × gomb
(az utolsó sor nem törölhető).

---

## Megerősítő modal

Az **Előnézet / Kiállítás** gombra kattintva megjelenik egy összefoglaló ablak, amely tartalmazza
a fejléc-adatokat és a tételsorokat a becsült végösszeggel. Ekkor a számla még **nem** kerül
rögzítésre.

Az összefoglalóban a **Kiállítás** gombra kattintva a rendszer elküldi az adatokat. A
**Mégse** gombbal visszaléphetsz az űrlapra és javíthatsz.

---

## A sorszám

> **Fontos:** a számla bizonylati sorszámát a rendszer a kiállítás pillanatában, szerver oldalon
> osztja ki. Ezért az összefoglaló ablakban még nem látható a sorszám — csak a sikeres kiállítás
> után, a zöld visszaigazolásban jelenik meg (pl. `SZ-202407-000003`).

Ez a hézagmentes sorszámozás követelménye: két párhuzamos kiállítás soha nem kap azonos sorszámot.

---

## Sikeres kiállítás után

A visszaigazolás ablakban két lehetőség jelenik meg:

- **Bizonylat megtekintése** — megnyitja az új számla részletes oldalát (ahol fizethetsz,
  PDF-et tölthetsz le stb.).
- **Vissza a listához** — visszavezet a bizonylatok listájára.

A kiállított számla azonnal **kiállított** státuszban jelenik meg, és ha a NAV Online Számla
integráció be van kapcsolva, a beküldés a háttérben automatikusan megtörténik.
