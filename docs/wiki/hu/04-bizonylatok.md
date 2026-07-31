# 4. Bizonylatok

Az oldalsáv **Bizonylatok** menüpontja egyetlen listán mutatja az összes számlát, nyugtát és
sztornót — nem kell külön menübe menni számla és nyugta között.
Vissza: [README.md](../README.md)

---

## A lista elrendezése

A táblázat alapból a leggyakrabban használt oszlopokat mutatja, de az elérhető oszlopok
köre ennél bővebb. A lista feletti **Oszlopok** gombra kattintva megnyílik az
oszlopválasztó: itt az egyes oszlopok jelölőnégyzettel ki- és bekapcsolhatók, a nem
rögzített oszlopok pedig a soruk elején lévő fogantyúval húzva átrendezhetők. Egyes
azonosító oszlopok (pl. a bizonylatszám) mindig láthatók maradnak. A beállítás
felhasználónként és cégenként külön megőrződik — másik cégre váltva a lista a saját
oszlop-preferenciáját mutatja.

A bizonylat sorszámára kattintva megnyílik a részletes oldal.

---

## Szűrők és keresés

**Típusszűrő (pill-gombok a lista felett):**

- Összes
- Számla
- Sztornó számla
- Nyugta
- Sztornó nyugta

Csak azok a típusgombok láthatók, amelyekhez a felhasználónak van jogosultsága.

**Deviza- és fizetési-állapot-szűrők:**

- Deviza: HUF / EUR / USD
- Fizetési állapot: Nyitott / Részben fizetve / Fizetve — ez a szűrő csak akkor jelenik meg,
  ha számlák (vagy sztornó számlák) vannak kiválasztva, nyugtáknál nincs értelme.

**Keresés:** a keresőmezőbe beírt szöveg a bizonylat száma és a partner neve szerint szűr.
A keresőmező jobb szélén lévő ✕ gomb törli a szöveget és visszaállítja a listát.

**Dátumszűrő:** „Dátumtól" és „Dátumig" mezők a kiállítás dátuma szerint szűkítik a listát.
A szűrők jobb szélén megjelenő ✕ törli a dátumtartomány-feltételt.

**Oldalanként megjelenítendő elemek száma** (per-page választó): 20, 50, 100, 200 vagy 500.
A kiválasztott lapméretet a rendszer felhasználónként és cégenként megjegyzi, így a lista
legközelebbi megnyitásakor is ez lesz érvényben. Ha a listát megosztott linkből nyitod meg,
és a link tartalmaz lapméretet, az arra a megnyitásra érvényesül, de a saját mentett
beállításodat nem írja felül.

---

## Lapozás

Ha az összes találat nem fér el egy oldalon, a lista alatt lapozó jelenik meg (előző/következő,
oldalszámok, ellipszis a sok oldalszám esetén). A szűrők módosítása visszaállítja az 1. oldalra.

---

## Bizonylat részletei

A bizonylat sorszámára kattintva megjelenik a részletes oldal, ahol láthatók a fejléc-adatok,
tételsorok, összesítők és — számlánál — a fizetési előzmények.

---

## Sztornózás

Ha egy bizonylat részletes oldalán az **Sztornó** gomb megjelenik (kiállított státuszú, nem
sztornó), a gombra kattintva megerősítő kérdés jelenik meg. Jóváhagyás után:

- A rendszer egy **sztornó-bizonylat**ot hoz létre (negatív tételösszegekkel), és az
  eredeti bizonylat státusza `sztornózott` lesz.
- A sztornó- és az eredeti bizonylat kölcsönösen hivatkoznak egymásra.
- Egy bizonylat csak egyszer sztornózható.

> A sztornó SimplePay-jal fizetett számlánál speciális: ott a **Visszatérítés** gomb jelenik meg
> a Sztornó gomb helyett. Részletek: [5. fejezet](05-fizetesek.md).

---

## PDF letöltése

A részletes oldalon a **PDF** gomb letölti a bizonylat PDF fájlját. A fájl neve a bizonylat
sorszáma (pl. `SZ-202407-000003.pdf`). Ha a PDF a kiállításkor már el lett mentve a szerveren,
azt adja vissza; ha valami okból nem volt elmentve, a rendszer helyszínen generálja.

---

## PDF újragenerálása

A **PDF újragenerálása** gomb külön jogosultsághoz kötött: a kiállított számla, illetve
nyugta PDF-jének újragenerálására vonatkozó jog szükséges hozzá — a kettő egymástól
független jogosultság. Megerősítő kérdés után:

- A korábbi PDF-fájl archivált másolatként megmarad (időbélyeggel ellátva), nem törlődik.
- Új PDF generálódik a jelenlegi sablonnal és adatokkal.
- Az esemény az audit naplóba kerül.

Ezt a funkciót tipikusan akkor érdemes használni, ha a céglogó vagy az adatok megváltoztak, és
a bizonylat frissített PDF-jére van szükség.
