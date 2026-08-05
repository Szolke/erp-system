# 11. Eszközök

Az oldalsáv **Eszközök** menüpontja a cég fizikai eszközeit tartja nyilván: POS
terminálokat, mobiltelefonokat, nyomtatókat és minden mást, amit tételesen, gyári szám
szerint kell számon tartani. A modul cégenként kapcsolható be/ki — ha nem látod a
menüpontot, a cégednél nincs bekapcsolva, vagy nincs jogosultságod hozzá. Minden eszköz
ahhoz a céghez tartozik, amelyikben rögzítetted; másik cég eszközei nem látszanak.
Vissza: [README.md](../README.md)

---

## Az eszközlista

A lista név szerinti sorrendben mutatja az eszközöket, a következő oszlopokkal:

| Oszlop | Tartalom |
|---|---|
| Név | A rendszer által generált azonosító (l. lentebb). Mindig látható. |
| Gyári szám | A gyártótól származó sorozatszám. |
| IMEI | Mobileszközöknél az IMEI-azonosító; ha nincs, `—` látszik. |
| Eszköztípus | Melyik típusba tartozik (pl. Mobiltelefon, Teya POS terminál). |
| Státusz | Az eszköz aktuális állapota, színes címkeként. |
| Műveletek | Szerkesztés és Törlés gombok. Mindig látható. |

Az oszlopok testre szabhatók a lista feletti **Oszlopok** gombbal, és a lapméret is
beállítható — mindkettő ugyanúgy működik, mint a Bizonylatok listájánál
([4. fejezet](04-bizonylatok.md)), és a rendszer a választásodat felhasználónként és
cégenként megjegyzi. A **Név** és a **Műveletek** oszlop rögzített: nem kapcsolható ki és
nem is húzható át máshova.

A lista **rendezhető is**: a rendezhető oszlopok fejlécére kattintva a szokásos három
állapot (növekvő → csökkenő → alapértelmezett) között vált a sorrend, ugyanott leírt módon.
A választott rendezést a rendszer az oszlopokkal együtt megjegyzi.

A lista fölötti keresőmező egyszerre keres a **névben, a gyári számban és az IMEI-ben** —
elég egy részletet beírni, nem kell a teljes azonosító. A találatok száma a táblázat alatt
látszik („Összesen: N db"), alatta a lapozó.

Ha még egyetlen eszközt sem rögzítettél, a táblázat helyén a *„Még nincs rögzített eszköz."*
üzenet jelenik meg.

---

## Eszköz felvétele

Az eszközök létrehozásának jogával a lista jobb felső sarkában megjelenik a **+ Új eszköz**
gomb. Az űrlapon három mező szerepel:

- **Gyári szám** — kötelező. A cégen belül egyedinek kell lennie: ha már van ilyen gyári
  számú eszközöd, a mentés hibaüzenettel elutasításra kerül.
- **IMEI** — opcionális. Ha kitöltöd, ennek is egyedinek kell lennie a cégen belül.
- **Eszköztípus** — kötelező, legördülő listából választható. A lista a globális (minden
  cég számára elérhető) és a saját céged által felvett típusokat egyaránt tartalmazza.

**A nevet nem te adod meg — a rendszer generálja**, a mentés pillanatában. A név három
részből áll: a céged prefixe, az eszköztípus kódja és egy ötjegyű sorszám, alulvonással
elválasztva. Például egy `DEMO` prefixű cégnél az első `TEYA` kódú eszköz neve
`DEMO_TEYA_00001` lesz, a következőé `DEMO_TEYA_00002`. A sorszámozás típusonként külön
fut, tehát az első `MOBIL` kódú eszköz `DEMO_MOBIL_00001` néven jön létre. A név a felvételi
űrlapon még nem látszik, csak mentés után.

> A név elején álló prefix a cégnél beállított **értékesítő csoport prefix**
> ([6. fejezet](06-beallitasok.md), Cégbeállítások). Ha a cégednél ez nincs kitöltve,
> az eszköz nem hozható létre — előbb a Cégbeállításokban add meg a prefixet.

Mentés után a rendszer visszavisz az eszközlistára, ahol az új eszköz már a generált nevével
szerepel.

---

## Eszköz szerkesztése

A lista **Szerkesztés** gombja (az eszközök szerkesztésének jogával) nyitja meg az adatlapot.
Itt már négy mező látszik, de nem mind módosítható:

- **Név** — csak olvasható. A generált azonosító a létrehozás után véglegesen rögzített.
- **Eszköztípus** — a legördülő megjelenik, de le van tiltva. A típus utólag nem
  változtatható meg, mert a név is belőle képződött.
- **Gyári szám** és **IMEI** — módosítható (az egyediségi szabály itt is érvényes).
- **Státusz** — módosítható, a négy állapot közül választható.

Az adatlap alján látszik, ki hozta létre és ki módosította utoljára az eszközt
([8. fejezet](08-egyeb.md)).

---

## Állapotok

Egy eszköz négy állapot valamelyikében lehet. Az állapot **tájékoztató jellegű**: nincs
mögötte automatikus működés, te állítod át kézzel, ahogy az eszköz sorsa alakul.

| Állapot | Jelentése |
|---|---|
| Aktív | Használatban vagy raktáron, rendben van. |
| Kiadva | Valakinél/valahol van, kiadott állapotban. |
| Szervizben | Javításon van. |
| Selejtezve | Kivonva a használatból. |

Új eszköz mindig **Aktív** állapotban jön létre; ez a felvételi űrlapon nem is
választható, csak szerkesztéskor módosítható.

---

## Eszköz törlése

A lista **Törlés** gombja (az eszközök törlésének jogával) megerősítést kér, majd
véglegesen eltávolítja az eszközt. A törlés nem vonható vissza, és a felszabaduló sorszám
nem kerül újrafelhasználásra — a következő eszköz a soron következő számot kapja.

---

## Eszköztípusok

Az eszköztípus adja az eszköz nevének középső tagját, ezért eszközt csak létező típusra
lehet felvenni. A típusok karbantartása nem itt, hanem a **Beállítások → Eszköztípusok**
oldalon történik — a globális típusok listáját, a saját céges típus felvételét és a
hatókör-jelölést a [6. fejezet](06-beallitasok.md) írja le.

---

## Jogosultságok

Az eszközök megtekintése, létrehozása, szerkesztése és törlése négy külön jogosultság. Ha
csak megtekintési jogod van, a lista megnyílik, de a **+ Új eszköz**, a **Szerkesztés** és a
**Törlés** gomb nem jelenik meg. A jogosultságok kiosztását a
[7. fejezet](07-felhasznalok-jogosultsagok.md) írja le.
