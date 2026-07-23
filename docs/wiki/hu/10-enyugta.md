# 10. NAV eNyugta

Az oldalsáv **eNyugta jelentések** és a Beállítások → **NAV eNyugta** menüpontja a NAV
nyugtaadat-szolgáltatási kötelezettség teljesítését támogatja: a számítógéppel kiállított
nyugták napi összesítőjét kell a NAV felé jelenteni. A modul cégenként kapcsolható be/ki, és
a nyugta-funkcióhoz kötődik — ha a cég nem állít ki nyugtát, nincs is szükség rá. (A
nyugtaadat-szolgáltatási kötelezettség kizárólag a nyugtát ténylegesen kiállító
vállalkozásokat érinti.)
Vissza: [README.md](../README.md)

---

## Automatikus napi jelentés-generálás

A napi jelentések NEM a felületen, kézzel jönnek létre — egy háttérfolyamat generálja őket
automatikusan, minden nap hajnalban, az előző napra, minden olyan cégre, ahol a modul aktív.
A felhasználó dolga csak a már elkészült jelentések megtekintése és exportálása; új
jelentést manuálisan nem lehet (és nem is kell) indítani.

---

## A jelentések listája

A „NAV eNyugta — Jelentések" oldal szűrhető dátumtartományra és állapotra. A táblázat
oszlopai (testreszabhatók, ugyanúgy, mint a Bizonylatok listájánál, az „Oszlopok" gombbal):
nap, típus, állapot, nyugtaszám, bruttó összeg.

> Az állapot-szűrő öt lehetőséget kínál (Piszkozat, Kész, Beküldés alatt, Elfogadva,
> Elutasítva), de mivel a tényleges NAV-beküldés ma nem érhető el, a jelentések piszkozat
> állapotban maradnak — a többi opcióra a gyakorlatban nincs találat.

Ha az elmúlt 30 napban nem volt kiállított nyugta, a lista tetején egy tájékoztató sáv
jelzi ezt.

---

## A jelentés részletei

A jelentés sorára kattintva megnyílik a napi részletező: ÁFA-kategóriánkénti bontás
(nettó/ÁFA/bruttó/nyugtaszám soronként), valamint napi összesítő.

**CSV-export** — ez a modul ma működő útja: a jelentés letölthető CSV-fájlként, amelyet a
felhasználó kézzel tölt fel a NAV KOBAK-portáljára. Ez teljes értékű megoldás a
nyugtaadat-szolgáltatás teljesítésére, amíg a gépi (automatikus) beküldés nem elérhető.

---

## Beállítások

A Beállítások → NAV eNyugta oldalon állítható be:

- **Adószám** (8 jegyű törzsszám)
- **Üzemmód** — „Teszt (mock, NAV-kapcsolat nélkül)", „NAV teszt", vagy „Éles"
- **Bázis URL felülbírálás** (opcionális)
- **„Nullás napok jelentésének beküldése is"** kapcsoló — ez a kapcsoló ma nem csinál semmit
  a gyakorlatban, mert a tényleges beküldés egyáltalán nem elérhető; majd csak akkor lesz
  releváns, amikor a NAV-beküldés élesedik.
- **Technikai felhasználó** — bejelentkezési név, jelszó, aláírókulcs, cserekulcs. Ezek a
  mezők titkosítva tárolódnak, és a felületen soha nem olvashatók vissza — csak azt látod,
  hogy „Beállítva" vagy „Nincs beállítva". Üresen hagyva mentéskor a korábbi érték megmarad.

**Átmásolás az Online Számla beállításokból** — egy gomb, amely megerősítés után átmásolja a
NAV Online Számla technikai felhasználójának adatait ide. A megerősítő ablak figyelmeztet:
ez felülírja a fent beállított adatokat, és nem garantált, hogy ugyanaz a technikai
felhasználó ténylegesen működik az eNyugta interfészen is.

---

## A NAV-beküldés jelenlegi korlátai

A tényleges, gépi NAV-beküldés ma nem működik — ez **nem fejlesztési elmaradás, hanem
NAV-oldali függőség**: a NAV a nyugtaadat-szolgáltatáshoz eddig nem publikált bázis-URL-t,
sem teszt-, sem éles környezetre. Ameddig ez nem történik meg, a beküldés technikailag nem
megvalósítható — függetlenül attól, hogy az Üzemmód mezőnél Éles van kiválasztva. A fenti
CSV-exporttal és a NAV KOBAK-portálján való kézi rögzítéssel teljesíthető a kötelezettség.

---

## Jogosultságok

A jelentések megtekintése és a beállítások szerkesztése külön jogosultsághoz kötött — ha
csak megtekintési jogod van, a Beállítások oldal mezői nem szerkeszthetők.
