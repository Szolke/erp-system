# 1. Áttekintés és bejelentkezés

Ez a kézikönyv nyolc fejezetből áll. Az egyes témák (számlakiállítás, bizonylatok, fizetések stb.)
a fejezetek elején linkelt tartalomjegyzékben találhatók. Ha valamit nem találsz, nézd meg a
[README.md](../README.md) teljes listát.

---

## Mi ez a rendszer?

Ez az ERP (vállalatirányítási) rendszer kis- és közepes vállalkozások számla- és nyugtakezelését,
partnernyilvántartását, fizetési folyamatait és NAV Online Számla integrációját támogatja.

A rendszer főbb területei:

- **Számlázás és nyugtázás** — számla és nyugta kiállítása, sztornózása, PDF letöltése
- **Törzsadatok** — partnerek és termékek/szolgáltatások nyilvántartása
- **Fizetések** — kézi fizetésrögzítés és SimplePay online fizetési integráció
- **NAV Online Számla** — a kiállított számlák automatikusan bekerülnek a NAV rendszerébe
  (ha a cég beállításainál engedélyezve van)
- **Több cég** — egy felhasználó egyszerre több cég adatait is kezelheti, ha hozzá van rendelve

---

## Bejelentkezés

A rendszer a böngészőben fut. Nyisd meg a frontend URL-t, és a bejelentkező képernyőn add meg
az email-cím és jelszó kombinációt.

**Helyi tesztkörnyezethez** (fejlesztői demo):
- Email: `test@example.com`
- Jelszó: `password`

Sikeres bejelentkezés után a rendszer az utoljára aktív cégkontextusba visz.

---

## A felület elrendezése

Bejelentkezés után két fő terület látható:

- **Oldalsáv (sidebar)** — a bal oldalon, ez a navigáció fő eszköze. A fejlécben a cégváltó
  látható (részletek lent), alatta a menüpontok (Bizonylatok, Partnerek, Termékek és a
  Beállítások almenü). Az oldalsáv alján a nyelvváltó, a sötét/világos mód kapcsolója, a
  felhasználónév és a **Kijelentkezés** gomb található.
- **Fő tartalom** — a jobb oldali, nagyobb terület; itt jelenik meg az adott oldal (lista,
  részletek, űrlap).

Az oldalsáv összecsukható: a fejlécben lévő nyíl gombbal ikonméretre szűkíthető, így több hely
marad a tartalomnak. Az állapot munkamenetről munkamenetre megmarad.

---

## Cégváltó

Ha a fiókodhoz egynél több cég van rendelve, az oldalsáv fejlécében egy legördülő menü jelenik
meg a cégek nevével. Innen egy kattintással válthatsz a cégek között — az összes lista, bizonylat
és beállítás azonnal az új cég adatait mutatja.

Ha csak egy céghez tartozol, a cégváltó helyett a cég neve jelenik meg szövegként.

> **Cégek létrehozása** — szuperadmin-felhasználók a Beállítások → **Cégek** menüpontban hozhatnak
> létre új cégeket és rendelhetnek hozzájuk felhasználókat. Ez a menüpont normál felhasználóknak
> nem látható.

---

## Nyelvváltó és sötét mód

Az oldalsáv alján találod:

- **Nyelvváltó** — HU / EN / DE között váltható; a felületen megjelenő szövegek azonnal
  az új nyelven jelennek meg. Részletek: [8. fejezet](08-egyeb.md).
- **Sötét mód kapcsoló** (Hold/Nap ikon) — a megjelenés sötét vagy világos témára váltható.
  A választás böngészőben tárolódik. Részletek: [8. fejezet](08-egyeb.md).
