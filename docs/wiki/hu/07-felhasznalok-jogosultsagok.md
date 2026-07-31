# 7. Felhasználók és jogosultságok

A rendszer szerepkör-alapú jogosultság-kezeléssel (RBAC) működik. Az adminisztrátori felületek
a Beállítások almenüben találhatók: **Felhasználók** és **Csoportok**.
Vissza: [README.md](../README.md)

---

## Hogyan működik a jogosultság-rendszer?

Minden művelet (pl. számlakiállítás, partner megtekintése, beállítások módosítása) egy
**jogosultság-kulcshoz** van kötve. Egy felhasználó akkor hajthat végre egy műveletet, ha:

1. Tagja egy csoportnak, amely rendelkezik azzal a joggal, **vagy**
2. Az adott jog egyedi **felülírásként** engedélyezve van a felhasználónál.

A **tiltás (deny) erősebb az engedélyezésnél**: ha egy csoport engedélyez egy jogot, de a
felhasználón deny override van beállítva, a jog **nem** lesz érvényes.

---

## Csoportok (Beállítások → Csoportok)

A csoport egy névvel ellátott jogosultság-csomag. Például: „Pénzügy" csoport kaphatja a
számla/nyugta/fizetés-kezelési jogokat, a „Törzsadatkezelő" csoport a partner/termék/cég
jogokat.

A csoport részletes oldalán:

- Bejelölhető, hogy a csoport melyik jogosultságokat kapja (modulonként csoportosítva).
- Megtekinthető és módosítható a csoport taglistája.

---

## Felhasználók (Beállítások → Felhasználók)

A felhasználók listáján látható minden aktív felhasználó, csoporttagságaikkal.

### Csoporttagság módosítása

A felhasználó részletes oldalán a **Csoportok** szekció mutatja, melyik csoportokba tartozik.
Egy legördülőből új csoport adható hozzá; a **Eltávolítás** gombbal a tagság megszüntethető.
Ehhez a csoportok kezelésének — létrehozásuknak és jogosultság-listáik szerkesztésének —
a jogosultsága szükséges.

### Egyedi jog-felülírások

A felhasználó részletes oldalán a **Jogosultságok** szekció tartalmaz minden jogot, modulonként.
A jog-soron lévő gomb három állapot között váltható:

- **Nincs felülírás** — a csoport döntése érvényes (alapértelmezett).
- **Engedélyezve** (zöld) — a felhasználó akkor is megkapja a jogot, ha egyik csoportja sem adja.
- **Tiltva** (piros) — a felhasználó nem hajthatja végre a műveletet, még ha csoportja adna rá jogot.

A csoporttól örökölt jogok kék háttérrel jelöltek, hogy egyértelmű legyen, melyik sorokat
érintik az overridok.

Az „Érzékeny" feliratú jogok (pl. PDF újragenerálása, sztornózás) külön megjelenítve láthatók.

**Tömeges beállítás:** a lista fölött, a keresőmező mellett két gomb áll — **Összes
engedélyezése** és **Összes tiltása** —, amelyek egy lépésben minden jogra beállítják a
választott felülírást. Modulonként is van rá mód: a modul fejlécében lévő pipa, illetve ×
ikon az adott modul összes jogát engedélyezi vagy tiltja.

> **Figyelem:** az **Összes engedélyezése / Összes tiltása** gomb akkor is a teljes
> joglistára hat, ha a keresőmezővel éppen leszűkítetted a megjelenített sorokat — nem
> csak a látható találatokra. A modul-szintű ikonok ezzel szemben pontosan az adott modul
> jogaira vonatkoznak.

A tömeges kapcsolók az egyes sorokhoz hasonlóan egyelőre csak a képernyőn állítják át az
értékeket.

A változtatásokat a **Felülírások mentése** gomb rögzíti. Ez a gomb és a tömeges kapcsolók
is csak a jog-felülírásra jogosult felhasználóknak jelennek meg.

---

## Szuperadmin szerep

A szuperadmin-felhasználó **minden jogot automatikusan megkap** — nem szükséges csoporthoz
rendelni, és a deny override sem hat rá. A szuperadmin-felhasználó:

- Látja a **Cégek** menüpontot (új cég létrehozása, váltás).
- Nem törölhető és nem tiltható le a rendszerből.
- A felhasználók listáján „Szuperadmin" badge jelöli.

Az első szuperadmin fiók beállításáról az élesítési útmutató szól; a demo környezetben a
`test@example.com` / `password` felhasználó szuperadmin.
