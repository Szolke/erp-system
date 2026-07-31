# 8. Egyéb: nyelvváltás, sötét mód, audit napló

Vissza: [README.md](../README.md)

---

## Nyelvváltás

A felhasználói felület három nyelven érhető el: **magyar (HU)**, **angol (EN)** és
**német (DE)**.

A nyelvet az **oldalsáv alján** lévő három gombbal (HU · EN · DE) lehet váltani. A kiválasztott
nyelv azonnal életbe lép — az összes felirat, gombszöveg és hibaüzenet az új nyelven jelenik meg.
A beállítás a fiókhoz tartozik, és a következő bejelentkezés után is megmarad.

---

## Sötét / világos mód

Az oldalsáv alján, a nyelvváltó gombsor jobb szélén található egy **Hold (🌙) / Nap (☀️)** ikon.
Erre kattintva a felület sötét és világos téma között vált.

- A választás a böngészőben tárolódik (munkamenetről munkamenetre megmarad).
- Ha nem volt korábban beállítás, a rendszer az operációs rendszer témáját veszi alapul.

---

## Audit napló (Beállítások → Audit napló)

Az audit napló rögzíti a rendszerben végzett fontosabb műveletek előzményeit. A napló
csak az audit napló megtekintésére jogosult felhasználóknak érhető el.

### Mit tartalmaz egy bejegyzés?

Minden sor a következőket mutatja:

- **Időpont** — mikor történt az esemény
- **Felhasználó** — ki hajtotta végre
- **Esemény** — a művelet neve (pl. `invoice.cancel`, `auth.login`, `company.manage`)
- **Rekord** — melyik adatbázis-objektumot érintette (pl. `Invoice#42`)
- **Előző / Új értékek** — mi változott (csak ha az eseményhez van adatváltozás)

Az oszlopok itt is testre szabhatók: a lista feletti **Oszlopok** gombbal — a
Bizonylatoknál megismert módon — az egyes oszlopok ki-/bekapcsolhatók és átrendezhetők.

### Szűrés

A napló feletti keresőmezőbe beírhatod a keresett eseménynevet (pl. `invoice` beírásával csak
a számlával kapcsolatos eseményeket látod). A **keresés** gomb frissíti a listát.

### Mire való?

Az audit napló segít nyomon követni:

- ki állított ki vagy sztornózott bizonylatot,
- mikor módosultak a cég adatai,
- ki lépett be a rendszerbe,
- PDF újragenerálási előzményeket.

A napló bejegyzései nem törölhetők; a rendszer automatikusan rögzíti őket a vonatkozó
műveletek végén.

### „Létrehozta / Módosította" lábléc a törzsadat-oldalakon

A teljes audit napló mellett a törzsadat-oldalak alján egy diszkrét lábléc mutatja meg
ugyanezt a rekord szintjén: **Létrehozta** és **Módosította** — kinek a nevéhez és melyik
időponthoz köthető az adott rekord. Így a leggyakoribb kérdésre („ki nyúlt ehhez utoljára?")
nem kell megnyitni az audit naplót.

A lábléc ezeken az oldalakon jelenik meg:

- Cégbeállítások (a cégadat-űrlap alatt — a cég rekordjára vonatkozik, ezért nem az oldal
  legalján áll, ahol már más szekciók következnek)
- Partner, Termék, Eszköz adatlap
- Értékesítő csoport adatlap
- Felhasználó és Csoport adatlap

Amit láthatsz benne:

- **Név · időpont** — a rekordot ez a felhasználó hozta létre, illetve módosította utoljára.
- **Rendszer** — a sort nem felhasználó írta, hanem a rendszer (pl. telepítéskori
  alapadat-feltöltés vagy háttérfolyamat); ilyenkor időpont nem jelenik meg.
- **—** — a művelet felhasználója már nem létezik a rendszerben; az időpont ilyenkor is
  látszik.

Új, még nem mentett rekordnál a lábléc egyáltalán nem jelenik meg.
