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
lekérdezéséhez `audit.view` jogosultság szükséges.

### Mit tartalmaz egy bejegyzés?

Minden sor a következőket mutatja:

- **Időpont** — mikor történt az esemény
- **Felhasználó** — ki hajtotta végre
- **Esemény** — a művelet neve (pl. `invoice.cancel`, `auth.login`, `company.update`)
- **Rekord** — melyik adatbázis-objektumot érintette (pl. `Invoice#42`)
- **Előző / Új értékek** — mi változott (csak ha az eseményhez van adatváltozás)

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
