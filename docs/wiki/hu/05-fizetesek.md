# 5. Fizetések

A fizetések kezelése a **számla részletes oldalán** történik (Bizonylatok → számla megnyitása).
Vissza: [README.md](../README.md)

> **Nyugtáknál nincs fizetés-rögzítés**: a nyugta a kiállítás pillanatában automatikusan
> fizetettnek minősül. A fizetési funkciók csak számlákra vonatkoznak.

---

## Kézi fizetésrögzítés

A számla részletes oldalán, a tételsorok alatt jelenik meg a **Fizetések** szekció. Ha a számla
nincs teljesen fizetve, a szekció alján egy rögzítő űrlap jelenik meg.

Az űrlap mezői:

- **Összeg** — a befizetett összeg a bizonylat devizájában.
- **Fizetési mód** — válassz a beállított fizetési módok közül.
- **Dátum** — alapértelmezetten a mai nap; szükség esetén módosítható.

Egy számlához több részbefizetés is rögzíthető.

---

## A „Nyitott egyenleg" gomb

Az összeg-mező mellett megjelenik egy **„Nyitott egyenleg: X"** feliratú gomb, ha van még
be nem fizetett összeg. Rákattintva az összeg-mező automatikusan kitöltődik a hátralévő
egyenleggel (HUF esetén egész szám, más devizánál 2 tizedesjegy).

---

## Fizetési állapot

A számla fizetési állapota automatikusan frissül a rögzített befizetések alapján:

- **Nyitott** — még nincs befizetett összeg
- **Részben fizetve** — van befizetés, de a teljes összeg nincs rendezve
- **Fizetve** — a teljes bruttó összeg rögzítve van

Ha a számla teljesen ki van fizetve, az összeg-mező és a rögzítő gomb eltűnik.

---

## SimplePay online fizetés

Ha a cég SimplePay integrációja be van kapcsolva és be van állítva, a számla adatai alapján
online fizetési folyamat indítható. Ez a funkció a számla részletes oldalán egy **SimplePay**
gombbal jelenik meg — a fizetési folyamat az OTP SimplePay oldalán zajlik, majd visszairányít
a rendszerbe.

A sikeres SimplePay tranzakció automatikusan rögzítésre kerül fizetésként.

---

## SimplePay visszatérítés és sztornó

Ha egy számlát SimplePay-jal fizettek ki sikeresen, a **Sztornó** gomb helyett egy
**Visszatérítés** gomb jelenik meg.

A visszatérítés gombra kattintva (megerősítés után) a rendszer:

1. Kezdeményezi a visszatérítést a SimplePay API-n keresztül.
2. A tranzakció státuszát `visszatérítve`-re állítja.
3. Automatikusan elkészíti a sztornó számlát is.

Mindkét lépés egyszerre zajlik — külön sztornózni nem szükséges.

> **Fontos:** a SimplePay visszatérítés funkcionalitása sandbox-tesztelést igényel valódi
> merchant-adatokkal az élesítés előtt. Éles üzemben ellenőrizd a SimplePay visszaigazolásait.
