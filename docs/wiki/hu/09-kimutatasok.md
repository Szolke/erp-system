# 9. Kimutatások

Az oldalsáv **Kimutatások** menüpontja négy fülön mutat összesítő nézeteket a számlázási
adatokról: számlák, termékek, kintlévőség és ÁFA. A modul cégenként kapcsolható be/ki — ha
nem látod a menüpontot, a cégednél nincs bekapcsolva, vagy nincs jogosultságod hozzá.
Vissza: [README.md](../README.md)

---

## Közös szűrősáv

A Számlák, Termékek és ÁFA-összesítő fülön azonos elemekből épül fel a szűrősáv:
dátumtartomány-választó, **„Teljesítés szerint / Kiállítás szerint"** kapcsoló (melyik dátum
számítson az időszak meghatározásához), és gyorsválasztó pillák (Ez a hónap, Előző hónap, Ez
az év, Előző év). A **Kintlévőség fül szűrősávja más**: itt nincs tartomány, csak egyetlen
„Állapot dátuma" mező — a kintlévőség mindig egy adott naphoz viszonyítva értendő, nem egy
időszakra.

> A kimutatások megtekintése és az exportálása külön jogosultsághoz kötött — előfordulhat,
> hogy látod a kimutatást, de az **Exportálás CSV-be** gomb nem jelenik meg számodra.

---

## Számlák

KPI-kártyák (nettó, ÁFA, bruttó, kintlévő összeg), egy havi vagy napi bontású oszlopdiagram,
és egy táblázat időszakonkénti bontásban. Szűrhető partnerre és fizetési státuszra (nyitott /
részben fizetett / fizetett).

> **Fontos:** a fül alapból KIZÁRÓLAG a számlákat mutatja — a nyugták nem számítanak bele,
> a fül neve ellenére sem. A **„Nyugták is"** kapcsoló bekapcsolásával a diagram és a
> táblázat is bővül a nyugta-adatokkal (nyugta-darabszám, nyugta-bruttó összeg).

---

## Termékek

Táblázat a legkelendőbb termékekről/szolgáltatásokról: mennyiség, nettó árbevétel,
számla-darabszám, átlagos egységár, részesedés a teljes árbevételből. Lapozható. Rendezhető
árbevétel vagy mennyiség szerint.

> A fül tetején lévő diagram **mindig a top 10 terméket mutatja árbevétel szerint**,
> függetlenül attól, hogyan rendezed a táblázatot alatta — ha mennyiség szerint rendezel, a
> diagram akkor sem vált át.

---

## Kintlévőség

Egy adott naphoz (**„Állapot dátuma"**) viszonyítva mutatja, mely partnereknek van nyitott
tartozásuk, korosítási sávokba bontva: **Nem lejárt**, **0-30 nap**, **31-60 nap**,
**61-90 nap**, illetve **90+ nap**. Partnerenkénti és összesített sávos KPI-kártyák, valamint
egy partnerenkénti táblázat. Szűrhető egy adott partnerre.

---

## ÁFA-összesítő

Könyvelőnek szánt nézet: itt a **táblázat a fő elem** (időszak és ÁFA-kategória szerinti
bontásban, kategóriánkénti és mindösszesen sorral), a diagram csak **másodlagos, kisebb, és a
táblázat alatt** jelenik meg — és csak akkor, ha egynél több ÁFA-kategória érintett az
időszakban.

---

## Exportálás

Mind a négy fülön elérhető egy **Exportálás CSV-be** gomb (ha megvan hozzá a
jogosultságod), amely a fülön éppen aktív szűrőknek megfelelő adatokat tölti le CSV-fájlként.
