# 3. Nyugta kiállítása

A bizonylatok listájáról (Bizonylatok → **Új nyugta** gomb) indítható.
Vissza: [README.md](../README.md)

A nyugta kiállítása a számlához hasonló folyamat, de néhány fontos eltéréssel — ezeket
ez az oldal emeli ki. Az általános logika (tételsorok, megerősítő modal, sorszám) azonos
a [2. fejezettel](02-szamla-kiallitas.md).

---

## Eltérések a számlától

| Szempont | Számla | Nyugta |
|---|---|---|
| Partner | Kötelező | Opcionális — elhagyható (névtelen) |
| Deviza | HUF vagy EUR | HUF, EUR vagy USD |
| Árfolyam | Nincs külön mező | Kötelező, ha EUR vagy USD van kiválasztva |
| Kedvezmény | Soronként %-ban adható meg | Nincs |
| Megjegyzés | Van | Nincs |
| NAV Online Számla | Igen (ha engedélyezve) | Nem |
| Fizetési állapot | Nyitott → részben → fizetve | Azonnal fizettnek számít |
| Teljesítési dátum | Kötelező | Opcionális |

---

## Partner (névtelen nyugta)

A partnermező üres marad, ha a vevő neve nem szükséges. Az ilyen bizonylat a
listán „—" partnerrel jelenik meg, a megerősítő modalban és a PDF-en a „Névtelen"
felirat szerepel.

---

## Teljesítési dátum

Opcionálisan megadható. Ha üresen hagyod, a rendszer automatikusan a kiállítás dátumát
veszi teljesítési dátumként.

---

## Deviza és árfolyam

Ha EUR-t vagy USD-t választasz, megjelenik egy **Árfolyam** beviteli mező — ide kell beírni
az alkalmazandó középárfolyamot (pl. 410,5). Az MNB napi árfolyama a rendszerben
automatikusan frissül, de az árfolyamot manuálisan kell megadni a nyugta kiállításakor.

---

## Fizetési modell

A nyugta a kiállítás pillanatában **azonnal fizetettnek** minősül. Nincs külön fizetés-rögzítő
szekció, részfizetés nem követhető nyomon (ez a tervezett viselkedés, a nyugta egyszeri
kasszás tranzakciót jelöl).

Ha a jövőben nyitott/részben fizetett nyugtára van szükség, azt külön fejlesztés teszi lehetővé.
