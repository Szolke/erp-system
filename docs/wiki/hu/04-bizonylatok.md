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

### Rendezés az oszlopfejlécekkel

A rendezhető oszlopok fejléce kattintható gomb, a felirat mellett egy kis nyíl-ikonnal. A
fejlécre kattintva a rendezés **három állapot között** vált körbe:

1. **Első kattintás** — a lista az adott oszlop szerint rendeződik.
2. **Második kattintás** — ugyanaz az oszlop, de fordított irányban.
3. **Harmadik kattintás** — vissza a lista alapértelmezett rendezésére (a Bizonylatoknál ez
   a kelt szerint csökkenő sorrend).

Az első kattintás iránya oszloptípustól függ: szöveges oszlopnál növekvő (ábécésorrend),
dátum-, összeg- és darabszám-oszlopnál csökkenő — vagyis a legfrissebb, illetve a legnagyobb
érték kerül előre. Az ikon mutatja az aktuális állapotot: felfelé mutató nyíl a növekvő,
lefelé mutató a csökkenő rendezést jelenti, a kettős (fel-le) nyíl pedig azt, hogy ezen az
oszlopon nincs rendezés.

Egyszerre mindig egy oszlop szerint lehet rendezni: egy másik fejlécre kattintva az előző
rendezés megszűnik. **Nem minden oszlop rendezhető** — ahol a tartalom nem egyetlen
adatbázis-mezőből származik (pl. a felhasználók listáján a Csoportok oszlop, az audit
naplóban az Előző/Új értékek), ott a fejléc sima szöveg marad, nem kattintható.

A rendezés a teljes találati halmazra vonatkozik, nem csak az éppen látott oldalra, ezért
rendezésváltáskor a lista visszaugrik az első oldalra. A választott rendezést a rendszer — az
oszlopokhoz és a lapmérethez hasonlóan — felhasználónként és cégenként megjegyzi, tehát a
lista legközelebbi megnyitásakor is érvényben lesz. A rendezés viszont **nem része a
megosztható linknek**: ha valakinek elküldöd a lista címét, ő a saját mentett rendezését
látja. Az oszlopválasztó **Alapértelmezett visszaállítása** gombja a rendezést is
visszaállítja.

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

**Dátumszűrő:** a szűrősorban lévő **Dátum-tartomány** gomb (naptár ikonnal) nyit egy
naptár-panelt, amely a kiállítás dátuma szerint szűkíti a listát. A panelen két hónap
naptára látszik egymás mellett: az első kattintás a tartomány kezdetét, a második a végét
jelöli ki (ha a második korábbi dátumra esik, a rendszer felcseréli a kettőt). A kijelölés
csak az **Alkalmaz** gombra lép életbe — a naptáron kattintgatás önmagában még nem szűri a
listát, a **Mégsem** gomb pedig eldobja a megkezdett kijelölést. Ha van érvényes tartomány,
a gomb felirata azt mutatja a „Dátum-tartomány" felirat helyett.

A panel alján, a bal oldalon lévő **Szűrő törlése** gomb szünteti meg a
dátumtartomány-feltételt; ez a gomb inaktív, amíg nincs beállított tartomány. (A státusz- és
deviza-szűrő ezzel szemben a lista fölött megjelenő címke-sorból, az ✕ gombbal távolítható
el — a dátumtartomány szándékosan nem kerül be ebbe a címke-sorba.)

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
