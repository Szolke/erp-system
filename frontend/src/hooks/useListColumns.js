import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useAuth } from '../contexts/AuthContext'
import { useToast } from '../contexts/ToastContext'
import { useTranslation } from '../contexts/TranslationContext'
import { listPreferences as listPreferencesApi } from '../api/listPreferences'

const SAVE_DEBOUNCE_MS = 600

// Minimum-oszlop guard: egy lista soha ne redukálódjon ennél kevesebb látható
// oszlopra — egy fejléc és sorok nélküli, üres táblázat használhatatlan, és a
// felhasználó a saját oszlopválasztóján kívül semmiből nem tudná visszaállítani.
//
// NINCS kitüntetett "kötelező" oszlop: bármelyik lehet az utolsó megmaradó, a
// szabály csak a DARABSZÁMRA vonatkozik. A jelenlegi registryk mindegyikében
// van legalább egy `locked` (eleve elrejthetetlen) oszlop, ezért ez a szabály
// ma egyetlen listán sem korlátoz — sentinel egy jövőbeli, locked oszlop
// NÉLKÜLI listára, illetve a nem a ColumnPickeren át keletkező (kézzel, API-n
// írt) preferenciákra, amiket a backend nem validál.
//
// A ColumnPicker ugyanezt a konstanst olvassa a checkbox letiltásához, hogy a
// küszöb egy helyen legyen definiálva.
export const MIN_VISIBLE_COLUMNS = 1

// Melyik cég volt aktív, amikor legutóbb egy listaoldal CSATLAKOZOTT.
//
// Miért modul-szintű? A `Layout` az `<Outlet key={activeCompanyId} />`-del
// cégváltáskor a teljes listaoldalt ÚJRACSATOLJA, tehát a hook minden állapota
// és refje elvész — komponensen belül nem őrizhető meg, hogy "melyik cégről
// jöttünk". A címsorban viszont ottmarad az előző cég `?per_page=…`-a, amit egy
// friss mount különben jogos, megosztott linkből jövő kérésnek olvasna, és az
// elsőbbségi szabály miatt örökre kiütné a másik cég mentett lapméretét (ez volt
// a Bizonylatok és a NAV-napló listáján tapasztalt hiba). Egyszerre mindig csak
// egy listaoldal van a képernyőn, ezért egyetlen érték elegendő.
let lastMountedCompanyId = null

// Kizárólag tesztekhez: a fenti modul-szintű memória nullázása két eset között.
export function resetListColumnsCompanyMemory() {
  lastMountedCompanyId = null
}

/**
 * Listánként újrahasználható oszlopválasztó-állapot. A mentett preferenciát a
 * /api/me válaszából (AuthContext.listPreferences[listKey]) olvassa, a
 * módosítást debounce-olt PUT-tal menti a `user_list_preferences` backendbe.
 *
 * Optimista context-írás (miért): az /api/me válasza egy munkameneten belül
 * PILLANATKÉP — a sikeres PUT nem frissíti. Enélkül a listaoldal elhagyása és
 * visszatérése (a route-váltás LECSATOLJA a lapot, tehát a hook teljes state-je
 * elvész) az állott pillanatképből építene újra: ha a usernek a belépéskor még
 * nem volt mentett sora, a most beállított oszlopai helyett a kód szerinti
 * defaultok jönnének vissza. Ezért minden képernyő-állapotot változtató művelet
 * a VÁLTOZÁS PILLANATÁBAN visszaírja a kimenő payloadot a contextbe
 * (`mergeListPreference`), nem a PUT válaszából — a válasz-alapú írás egy
 * repülő kérés közben tett kattintást ütne vissza az alábbi újrainicializáló
 * effekten keresztül. A saját írásunkat ezért az effekt referencia szerint
 * felismeri és átugorja (l. `optimisticRef`).
 *
 * Merge-logika betöltéskor (miért `order` a kulcs, nem csak `visible`): a
 * mentett `columns.order` a MENTÉSKOR ismert oszlopkulcsok teljes listája
 * (látható ÉS elrejtett is), ezért ebből tudjuk eldönteni, hogy egy, a mentett
 * `visible`-ből hiányzó kulcs a usér által TUDATOSAN elrejtett oszlop-e (benne
 * van az `order`-ben, csak nem a `visible`-ben), vagy egy azóta a kódban
 * ÚJONNAN bevezetett oszlop (nincs benne az `order`-ben sem) — utóbbi esetben
 * a kód szerinti `default`-ra esünk vissza, hogy egy új oszlop ne maradjon
 * örökre láthatatlan a régi usereknek.
 *
 * Sorrendezés (drag&drop): az `order` mostantól a TÉNYLEGES megjelenítési
 * sorrendet hordozza, nem csak az "ismert kulcsok" halmazát. A `locked`
 * oszlopok (id-oszlop elöl, "actions" hátul, ha van) pozíciója fail-safe módon
 * kényszerített — akkor is, ha egy régi mentés vagy egy hibás `reorder()`-hívás
 * mást mondana (l. `enforceLockedPositions`). Új, a mentés óta bevezetett
 * oszlop a nem-locked halmaz VÉGÉN jelenik meg (determinisztikus, l.
 * `computeOrderedKeys`).
 *
 * Minimum-oszlop guard: a láthatóság soha nem eshet `MIN_VISIBLE_COLUMNS` alá —
 * sem a `toggle`-lel, sem betöltéskor (mentett preferenciából vagy defaultból).
 * Nincs kitüntetett kötelező oszlop, bármelyik lehet az utolsó megmaradó.
 *
 * Rendezés (`sort`): opcionális, csak azokon a listákon él, ahol a registry
 * `sortable: true`-val jelölt oszlopot tartalmaz. Alakja `{ by, dir }`, ahol a
 * `by` egy registry-oszlopkulcs, a `dir` pedig `'asc'`/`'desc'` — ugyanaz a
 * séma, amit a backend `UpdateListPreferenceRequest` `sort.by`/`sort.dir`-ként
 * eleve validál. `null`, ha a lista a szerver szerinti ALAPÉRTELMEZETT
 * rendezésén áll; ilyenkor a mezőt nem is küldjük ki (a végpont paraméter
 * nélkül úgyis az alapértelmezését adja).
 *
 * A `sort` szándékosan NEM kerül a címsorba (szemben a `page_size`-zal): a
 * jelenlegi konvencióban a URL a SZŰRÉST hordozza (mit lát a másik fél), a
 * mentett preferencia pedig a NÉZETET (hogyan látja) — az oszlopválasztás sem
 * URL-ben él. Ha egyszer megosztható rendezés is kell, a `page_size`
 * feloldási mintája (URL > mentett > default, cégváltáskor elévülő URL-érték)
 * változtatás nélkül ráhúzható.
 *
 * A NEM rendezhető listák viselkedése változatlan: ott a hook a mentett `sort`
 * mezőt továbbra is csak NYERSEN megőrzi (l. `persistedSortRef`), nem
 * értelmezi és nem írja felül.
 *
 * Lapméret (`page_size`): opcionális, csak a lapozó listákon. A hívó a
 * `defaultPageSize`-ban adja meg a lista saját alapértékét (jellemzően 20, az
 * audit-naplón 50), az `urlPageSize`-ban pedig a URL-ben EXPLICIT módon
 * megadott értéket, ha volt ilyen. Feloldási sorrend: URL > mentett preferencia
 * > lista-default. A `defaultPageSize` elhagyásával a hook a `page_size` mezőt
 * ugyanúgy csak megőrzi, mint korábban (nem lapozó listák). A URL-érték
 * cégváltáskor ELÉVÜL — l. `lastMountedCompanyId`.
 *
 * @param {string} listKey
 * @param {Array<{key: string, default?: boolean, locked?: boolean, permission?: string, sortable?: boolean, sortInitialDir?: 'asc'|'desc'}>} registry
 * @param {{defaultPageSize?: number|null, urlPageSize?: number|null}} [options]
 */
export function useListColumns(listKey, registry, options = {}) {
  const { defaultPageSize = null, urlPageSize = null } = options
  const { can, listPreferences, activeCompanyId, mergeListPreference, clearListPreference } = useAuth()
  const { t } = useTranslation()
  const addToast = useToast()

  const permittedColumns = useMemo(
    () => registry.filter((col) => !col.permission || can(col.permission)),
    [registry, can]
  )

  const saved = listPreferences?.[listKey]

  // Számít-e a URL-beli lapméret ezen a mounton? Csak akkor, ha a legutóbbi
  // listamount ugyanezen a cégen történt — különben ez a mount egy cégváltás
  // miatti újracsatolás, és a címsorban maradt érték a MÁSIK cég nézetére szólt.
  // Lazy useState: a döntés a mount pillanatában dől el, egyszer.
  const [mountUrlPageSize] = useState(() => (
    lastMountedCompanyId !== null && lastMountedCompanyId !== activeCompanyId ? null : urlPageSize
  ))
  // A URL-beli per_page csak a MEGNYITÁS pillanatában számít (megosztott link),
  // ezért refben rögzítjük — a későbbi URL-írásaink (l. a listaoldalak
  // szinkron-effektje) nem értelmezhetők újra "explicit felhasználói kérésként".
  const urlPageSizeRef = useRef(mountUrlPageSize)

  const [visibleKeys, setVisibleKeys] = useState(() => computeVisibleKeys(permittedColumns, saved))
  const [orderedKeys, setOrderedKeys] = useState(() => computeOrderedKeys(permittedColumns, saved))
  const [pageSize, setPageSizeState] = useState(() => resolvePageSize(urlPageSizeRef.current, saved, defaultPageSize))
  const [sort, setSortState] = useState(() => normalizeSort(saved?.sort, permittedColumns))
  const visibleKeysRef = useRef(visibleKeys)
  const orderedKeysRef = useRef(orderedKeys)
  const debounceRef = useRef(null)
  // A saját, optimista context-írásunk payloadja (referencia szerint), hogy az
  // újrainicializáló effekt meg tudja különböztetni a SZERVERRŐL érkező friss
  // állapottól, és ne írja vissza a helyi state-et a saját írásunkból.
  const optimisticRef = useRef(saved)
  // A még KI NEM KÜLDÖTT, a debounce-ablakban várakozó mentés payloadja.
  // Szándékosan külön él a timer-reftől: a lecsatoláskori flushnak pontosan
  // tudnia kell, hogy van-e valódi függő írás, vagy a PUT már elindult.
  const pendingSaveRef = useRef(null)
  const companyRef = useRef(activeCompanyId)
  // A MENTENDŐ lapméret. Szándékosan külön él a megjelenített `pageSize`-tól:
  // egy megosztott link ?per_page=100 értéke a nézetet átállítja, de NEM írja
  // felül a user mentett preferenciáját egy későbbi oszlop-kapcsolgatáskor.
  const persistedPageSizeRef = useRef(saved?.page_size)
  // A MENTENDŐ rendezés — NYERSEN, ahogy a szerveren áll. Azért nem a
  // normalizált `sort` állapotot mentjük vissza, mert a rendezés-UI nélküli
  // listákon (a 14-ből 13) a mentett érték ismeretlen alakú is lehet: ott a
  // korábbi "csak őrizd meg, ne értelmezd" viselkedés marad érvényben. A
  // `setSort` ezt a refet írja felül a saját, érvényes `{by, dir}`-jével.
  const persistedSortRef = useRef(saved?.sort)
  // A normalizált, KÉPERNYŐN érvényes rendezés — szinkron, hogy az ugyanabban a
  // tickben induló következő `toggleSort` már a friss állapotot lássa (l. `toggle`).
  const sortRef = useRef(sort)

  useEffect(() => { visibleKeysRef.current = visibleKeys }, [visibleKeys])
  useEffect(() => { orderedKeysRef.current = orderedKeys }, [orderedKeys])

  // A modul-szintű memóriát szándékosan CSAK a commit után írjuk: a fenti
  // döntés a render-fázisban olvassa, ott írni tisztátalan lenne (StrictMode
  // kétszer futtatja a render-testet, és a második futás már a saját írásunkat
  // látná — épp az elévülés bukna el).
  useEffect(() => { lastMountedCompanyId = activeCompanyId }, [activeCompanyId])

  // Az /api/me újratöltésekor (induláskor, cégváltáskor) a helyi állapot
  // eldobódik és a frissen betöltött mentett preferenciából épül újra — a
  // puszta toggle()/reorder() hívás viszont NEM változtatja meg a `saved`
  // referenciát, tehát ez a hatás azok közben nem fut le feleslegesen.
  //
  // Az `activeCompanyId` azért kell a függőségek közé, mert a `saved`
  // referencia-változása önmagában nem fedi le a cégváltást: ha EGYIK cégnek
  // sincs még mentett sora erre a listára, `saved` mindkét oldalon `undefined`,
  // vagyis azonos — az effekt nem futna le, és az előző cégen helyben beállított
  // (még csak a debounce-olt PUT-ban élő) érték bennragadna a képernyőn.
  useEffect(() => {
    const companyChanged = companyRef.current !== activeCompanyId

    if (companyChanged) {
      // Cégváltás ÚJRACSATOLÁS NÉLKÜL. A jelenlegi Layoutban ez nem fordul elő
      // (l. `<Outlet key={activeCompanyId} />`, ezért van a modul-szintű
      // memória is), de ha a lista egyszer mégis mountolva maradna a váltáson
      // át, itt ugyanaz a két teendő:
      //  - a még ki nem ment mentés a szerveren már a MÁSIK cég sorába íródna
      //    (a cég-kontextust a session adja), ezért eldobjuk — az előző cégen az
      //    utolsó, be nem küldött változtatás elveszhet, ez a kisebb rossz;
      //  - a megnyitáskori URL-beli lapméret a VÁLTÁS ELŐTTI cég nézetére szólt,
      //    ezért elévül.
      clearTimeout(debounceRef.current)
      debounceRef.current = null
      pendingSaveRef.current = null
      companyRef.current = activeCompanyId
      urlPageSizeRef.current = null
    }

    // A SAJÁT optimista írásunk nem inicializál újra: a helyi state már pontosan
    // ezt az értéket tükrözi, az újraszámolás viszont mellékhatásokkal járna —
    // a `visible` tömb sorrendje normalizálódna, egy URL-ből jövő ?per_page
    // pedig visszaütné a felhasználó épp választott lapméretét. Csak a
    // szerverről (/api/me: belépés, cégváltás, refreshAuth) érkező, ettől
    // KÜLÖNBÖZŐ állapot építi újra a hookot.
    if (!companyChanged && saved === optimisticRef.current) return

    setVisibleKeys(computeVisibleKeys(permittedColumns, saved))
    setOrderedKeys(computeOrderedKeys(permittedColumns, saved))
    persistedPageSizeRef.current = saved?.page_size
    setPageSizeState(resolvePageSize(urlPageSizeRef.current, saved, defaultPageSize))
    persistedSortRef.current = saved?.sort
    sortRef.current = normalizeSort(saved?.sort, permittedColumns)
    setSortState(sortRef.current)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listKey, saved, activeCompanyId])

  const sendSave = useCallback(async (payload) => {
    try {
      await listPreferencesApi.update(listKey, payload)
    } catch {
      // A helyi (optimista) állapotot szándékosan NEM görgetjük vissza — a
      // felhasználó választása a képernyőn érvényben marad, csak a
      // háttérben történő mentés esett ki; csendes, diszkrét jelzés.
      addToast(t('columns.save_error'), 'error')
    }
  }, [listKey, addToast, t])

  // A lecsatoláskori flush (lentebb) csak egyszer, `[]` függőséggel iratkozik
  // fel, ezért nem zárhat rá a legfrissebb `sendSave`-re — refen keresztül éri
  // el. (Ha `sendSave` a takarító-effekt függőségi listájába kerülne, egy
  // nyelvváltás miatti `t`-csere idő előtti flusht váltana ki.)
  const sendSaveRef = useRef(sendSave)
  useEffect(() => { sendSaveRef.current = sendSave }, [sendSave])

  // Lecsatoláskor a FÜGGŐ (még ki nem küldött) mentést nem eldobjuk, hanem
  // azonnal, fire-and-forget módon kiküldjük: a listát a debounce-ablakon belül
  // elhagyó felhasználó beállítása különben sosem jutna el a szerverig — a
  // context ugyan már tükrözné, de egy újratöltés után elveszne. A MÁR ELINDULT
  // PUT-ot nem ismételjük meg: a timer callbackje kinullázza a `pendingSaveRef`-et,
  // tehát flushölni csak akkor van mit, ha az ablak még nem telt le.
  useEffect(() => () => {
    clearTimeout(debounceRef.current)
    debounceRef.current = null
    const pending = pendingSaveRef.current
    pendingSaveRef.current = null
    if (pending) sendSaveRef.current(pending)
  }, [])

  const scheduleSave = useCallback((nextVisibleKeys, nextOrderedKeys) => {
    clearTimeout(debounceRef.current)

    const payload = buildPayload(nextVisibleKeys, nextOrderedKeys, persistedPageSizeRef.current, persistedSortRef.current)

    // Optimista context-írás a VÁLTOZÁS pillanatában (l. a hook fejlécét): a
    // kimenő payloadból, nem a PUT válaszából.
    optimisticRef.current = payload
    mergeListPreference(listKey, payload)

    pendingSaveRef.current = payload
    debounceRef.current = setTimeout(() => {
      const pending = pendingSaveRef.current
      pendingSaveRef.current = null
      debounceRef.current = null
      sendSaveRef.current(pending)
    }, SAVE_DEBOUNCE_MS)
  }, [listKey, mergeListPreference])

  const toggle = useCallback((key) => {
    const column = permittedColumns.find((col) => col.key === key)
    if (!column || column.locked) return

    // A következő állapot a refből (nem setState-updaterből) számolódik: a
    // `scheduleSave` mostantól a contextbe is ír, egy updater-függvény viszont a
    // RENDER-fázisban fut (StrictMode alatt kétszer is), ahonnan másik komponens
    // state-jét frissíteni tilos. A ref azonnali frissítése tartja korrektként
    // az ugyanabban a tickben induló következő hívást is.
    const prev = visibleKeysRef.current
    const hiding = prev.includes(key)

    // Minimum-oszlop guard (l. MIN_VISIBLE_COLUMNS): az utolsó látható oszlop
    // elrejtése no-op — se helyi állapotot, se mentést nem indít. A ColumnPicker
    // ilyenkor a checkboxot is letiltja, tehát ez az ág a nem-UI utakra
    // (programozott hívás, jövőbeli komponens) szóló védelem.
    if (hiding && prev.length <= MIN_VISIBLE_COLUMNS) return

    const next = hiding ? prev.filter((k) => k !== key) : [...prev, key]
    visibleKeysRef.current = next
    setVisibleKeys(next)
    scheduleSave(next, orderedKeysRef.current)
  }, [permittedColumns, scheduleSave])

  // A `reorder` a sortable (nem-locked) halmazon belüli áthelyezést fejezi ki:
  // "activeKey kerüljön overKey helyére". A hívó (ColumnPicker/dnd-kit) csak a
  // két kulcsot adja át — a végleges sorrendet (locked-kényszerítéssel együtt)
  // a hook dönti el, hogy a komponens ne ismerhesse a locked-pozíció szabályt.
  const reorder = useCallback((activeKey, overKey) => {
    if (activeKey === overKey) return
    const activeColumn = permittedColumns.find((col) => col.key === activeKey)
    const overColumn = permittedColumns.find((col) => col.key === overKey)
    if (!activeColumn || activeColumn.locked || !overColumn || overColumn.locked) return

    // Refből számolt következő állapot — l. a `toggle` indoklását.
    const prev = orderedKeysRef.current
    const fromIndex = prev.indexOf(activeKey)
    const toIndex = prev.indexOf(overKey)
    if (fromIndex === -1 || toIndex === -1) return

    const next = enforceLockedPositions(moveKey(prev, fromIndex, toIndex), permittedColumns)
    orderedKeysRef.current = next
    setOrderedKeys(next)
    scheduleSave(visibleKeysRef.current, next)
  }, [permittedColumns, scheduleSave])

  // Lapméret-váltás a PerPageSelectorból. A láthatóság/sorrend mentési útját
  // nem bontja meg: ugyanabba a debounce-olt PUT-ba (ugyanabba a `preferences`
  // jsonb-be) kerül, csak a `page_size` mezőbe.
  const setPageSize = useCallback((value) => {
    const next = Number(value)
    if (!Number.isFinite(next) || next <= 0) return

    persistedPageSizeRef.current = next
    setPageSizeState(next)
    scheduleSave(visibleKeysRef.current, orderedKeysRef.current)
  }, [scheduleSave])

  // Rendezés beállítása. A láthatóság/sorrend/lapméret mentési útját nem bontja
  // meg: ugyanabba a debounce-olt PUT-ba (ugyanabba a `preferences` jsonb-be)
  // kerül, csak a `sort` mezőbe.
  //
  // `by === null` → vissza a szerver szerinti ALAPÉRTELMEZETT rendezésre: a mező
  // ilyenkor kimarad a payloadból, nem `null`-ként megy ki (a backend
  // `sometimes|array` szabálya egy null-t úgyis eldobna, a kihagyás viszont
  // egyértelműen azt jelenti, hogy "nincs saját rendezés").
  const setSort = useCallback((by, dir) => {
    if (by == null) {
      persistedSortRef.current = undefined
      sortRef.current = null
      setSortState(null)
      scheduleSave(visibleKeysRef.current, orderedKeysRef.current)
      return
    }

    // Csak létező, rendezhetőnek jelölt oszlopra állhatunk — így egy elgépelt
    // vagy időközben megszűnt kulcs nem kerülhet be a mentett preferenciába
    // (a backend a kulcs LÉTEZÉSÉT szándékosan nem validálja).
    const column = permittedColumns.find((col) => col.key === by)
    if (!column || !column.sortable) return

    const next = { by, dir: dir === 'desc' ? 'desc' : 'asc' }
    persistedSortRef.current = next
    sortRef.current = next
    setSortState(next)
    scheduleSave(visibleKeysRef.current, orderedKeysRef.current)
  }, [permittedColumns, scheduleSave])

  // Egy oszlopfejlécre kattintás hatása. A HÁROM állapotú ciklus (növekvő →
  // csökkenő → alapértelmezett) szándékos: a listáknak van értelmes
  // alapértelmezett rendezésük (a bizonylatoknál kelt szerint csökkenő), amit
  // egy kétállapotú kapcsolóval már sehogy nem lehetne visszakapni — csak az
  // oszlopválasztó "Alapértelmezett visszaállítása" gombjával, ami viszont az
  // oszlopokat és a lapméretet is eldobná.
  //
  // A ciklus a HOOKBAN él (nem a fejléc-komponensben), ugyanazon az elven, mint
  // a `reorder`: a komponens csak az interakciót jelenti, a szabályt a hook
  // ismeri — így a későbbi, több listára kiterjesztett körben egy helyen marad.
  const toggleSort = useCallback((key) => {
    const column = permittedColumns.find((col) => col.key === key)
    if (!column?.sortable) return

    // Első kattintás iránya oszloponként állítható (`sortInitialDir`): dátumnál
    // és összegnél a "legnagyobb/legfrissebb elöl" a várt elsődleges nézet,
    // szövegnél az ábécésorrend.
    const initialDir = column.sortInitialDir === 'desc' ? 'desc' : 'asc'
    const current = sortRef.current

    if (!current || current.by !== key) return setSort(key, initialDir)
    if (current.dir === initialDir) return setSort(key, initialDir === 'asc' ? 'desc' : 'asc')
    return setSort(null)
  }, [permittedColumns, setSort])

  const reset = useCallback(async () => {
    // A függő mentést itt DOBJUK (nem flusheljük): a DELETE úgyis törli az egész
    // sort, egy utána befutó PUT pedig épp az imént visszaállított defaultot
    // írná felül a régi értékkel.
    clearTimeout(debounceRef.current)
    debounceRef.current = null
    pendingSaveRef.current = null

    const nextVisible = defaultVisibleKeys(permittedColumns)
    const nextOrdered = permittedColumns.map((col) => col.key)
    visibleKeysRef.current = nextVisible
    orderedKeysRef.current = nextOrdered
    setVisibleKeys(nextVisible)
    setOrderedKeys(nextOrdered)

    // A context is a mentés nélküli állapotra áll: a kulcs törlésével egy
    // későbbi remount a kód szerinti defaultokból épül (ugyanaz, amit az /api/me
    // is adna a DELETE után).
    optimisticRef.current = undefined
    clearListPreference(listKey)
    // A reset a TELJES preferencia-sort törli (DELETE), tehát a mentett
    // lapméret is elvész — a megjelenített értéket ezért vissza kell vinnünk a
    // mentés nélküli feloldásra (URL > lista-default), különben a képernyő és a
    // szerver állapota szétcsúszna a következő újratöltésig.
    persistedPageSizeRef.current = undefined
    setPageSizeState(resolvePageSize(urlPageSizeRef.current, null, defaultPageSize))
    // Ugyanez a rendezésre: a DELETE a `sort` mezőt is elviszi, tehát a nézet a
    // szerver szerinti alapértelmezett rendezésre áll vissza.
    persistedSortRef.current = undefined
    sortRef.current = null
    setSortState(null)
    try {
      await listPreferencesApi.remove(listKey)
    } catch {
      addToast(t('columns.save_error'), 'error')
    }
  }, [listKey, permittedColumns, defaultPageSize, addToast, t, clearListPreference])

  const isVisible = useCallback((key) => visibleKeys.includes(key), [visibleKeys])

  const allColumns = useMemo(
    () => orderedKeys.map((key) => permittedColumns.find((col) => col.key === key)).filter(Boolean),
    [orderedKeys, permittedColumns]
  )

  const visibleColumns = useMemo(
    () => allColumns.filter((col) => visibleKeys.includes(col.key)),
    [allColumns, visibleKeys]
  )

  const isDirty = useMemo(() => {
    const defaultKeys = defaultVisibleKeys(permittedColumns)
    const visibilityDirty = defaultKeys.length !== visibleKeys.length || !defaultKeys.every((k) => visibleKeys.includes(k))

    const defaultOrder = permittedColumns.map((col) => col.key)
    const orderDirty = orderedKeys.length !== defaultOrder.length || orderedKeys.some((k, i) => k !== defaultOrder[i])

    // A saját rendezés is "piszkos" állapot: enélkül a felhasználó egy tisztán
    // rendezés-módosítás után letiltott visszaállító gombot látna.
    return visibilityDirty || orderDirty || sort !== null
  }, [permittedColumns, visibleKeys, orderedKeys, sort])

  return { allColumns, visibleColumns, isVisible, toggle, reorder, reset, isDirty, pageSize, setPageSize, sort, setSort, toggleSort }
}

// A mentett `sort` értelmezése: csak a `{ by, dir }` alakú, LÉTEZŐ és
// rendezhetőnek jelölt oszlopra mutató érték számít. Minden más (hiányzó,
// ismeretlen kulcs, régi/idegen alak) `null` — ilyenkor a lista a szerver
// szerinti alapértelmezett rendezésen áll. A nyers értéket ez NEM dobja el, azt
// a `persistedSortRef` őrzi tovább.
function normalizeSort(raw, permittedColumns) {
  if (!raw || typeof raw !== 'object' || typeof raw.by !== 'string') return null
  if (raw.dir !== 'asc' && raw.dir !== 'desc') return null

  const column = permittedColumns.find((col) => col.key === raw.by)
  if (!column?.sortable) return null

  return { by: raw.by, dir: raw.dir }
}

// URL > mentett preferencia > lista-default. A URL azért erősebb, mert egy
// megosztott link (?per_page=100) szándékos, egyszeri kérés — a mentett érték
// pedig a user "szokásos" beállítása, ami a link bezárása után visszaáll.
function resolvePageSize(urlPageSize, saved, defaultPageSize) {
  if (urlPageSize != null) return urlPageSize
  if (saved?.page_size != null) return saved.page_size
  return defaultPageSize
}

// A minimum-oszlop szabály fail-safe alkalmazása a BETÖLTÉSI utakra: a mentett
// preferencia és a registry defaultjai is adhatnak elvileg üres halmazt (egy
// API-n át írt preferencia, vagy egy `default`/`locked` jelölés nélküli új
// registry). Ilyenkor az első engedélyezett oszlop marad látható —
// determinisztikus, registry-sorrend szerinti választás.
//
// Szándékosan CSAK a megjelenítést igazítja, mentést nem indít: a hookban
// minden PUT felhasználói művelethez kötött (l. `scheduleSave`), egy néma,
// mountkori írás pedig a felhasználó tudta nélkül módosítaná a szerver sorát.
// A szerver oldali érték a következő valódi módosításnál javul ki magától.
function enforceMinimumVisible(visibleKeys, permittedColumns) {
  if (visibleKeys.length >= MIN_VISIBLE_COLUMNS) return visibleKeys
  return permittedColumns.slice(0, MIN_VISIBLE_COLUMNS).map((col) => col.key)
}

function defaultVisibleKeys(permittedColumns) {
  const keys = permittedColumns.filter((col) => col.default || col.locked).map((col) => col.key)
  return enforceMinimumVisible(keys, permittedColumns)
}

function computeVisibleKeys(permittedColumns, saved) {
  const savedOrder = saved?.columns?.order ?? null
  const savedVisible = new Set(saved?.columns?.visible ?? [])

  const keys = permittedColumns
    .filter((col) => {
      if (col.locked) return true
      if (!savedOrder) return col.default
      return savedOrder.includes(col.key) ? savedVisible.has(col.key) : col.default
    })
    .map((col) => col.key)

  return enforceMinimumVisible(keys, permittedColumns)
}

// A mentett `order`-ből indul (ismert kulcsok, ebben a sorrendben), a végére
// fűzi a regisztryben létező, de a mentésből hiányzó (= azóta bevezetett)
// kulcsokat regisztry-sorrendben, majd a locked oszlopokat a kitűzött
// pozíciójukba kényszeríti — l. `enforceLockedPositions`.
function computeOrderedKeys(permittedColumns, saved) {
  const savedOrder = saved?.columns?.order ?? null
  const allKeys = permittedColumns.map((col) => col.key)

  let sequence
  if (savedOrder) {
    const keySet = new Set(allKeys)
    const known = savedOrder.filter((k) => keySet.has(k))
    const knownSet = new Set(known)
    const newKeys = allKeys.filter((k) => !knownSet.has(k))
    sequence = [...known, ...newKeys]
  } else {
    sequence = allKeys
  }

  return enforceLockedPositions(sequence, permittedColumns)
}

// A locked oszlopok a regisztryben MINDIG a lista elején és/vagy a végén
// állnak (soha középen — ez a konvenció, nem csak a jelenlegi adatok
// véletlen tulajdonsága). Ezért elég az első és az utolsó regisztry-elemet
// megnézni: ha locked, kitűzött pozícióba kényszerítjük, a köztes (nem-locked)
// kulcsokat pedig a bemeneti `sequence` sorrendjében hagyjuk — ez viszi át a
// drag-gel vagy a mentett `order`-rel kialakított sorrendet.
function enforceLockedPositions(sequence, permittedColumns) {
  if (permittedColumns.length === 0) return sequence

  const first = permittedColumns[0]
  const last = permittedColumns[permittedColumns.length - 1]
  const frontKey = first.locked ? first.key : null
  const backKey = (permittedColumns.length > 1 && last.locked) ? last.key : null

  const middle = sequence.filter((k) => k !== frontKey && k !== backKey)
  return [frontKey, ...middle, backKey].filter(Boolean)
}

function moveKey(keys, fromIndex, toIndex) {
  const next = keys.slice()
  const [moved] = next.splice(fromIndex, 1)
  next.splice(toIndex, 0, moved)
  return next
}

function buildPayload(visibleKeys, orderedKeys, pageSize, sort) {
  const payload = {
    columns: {
      visible: visibleKeys,
      order: orderedKeys,
    },
  }
  // A PUT teljes upsert, ezért a nem ezen a hívási úton keletkező mezőket is
  // vissza kell küldeni. A `pageSize` a mentendő lapméret, a `sort` a mentendő
  // rendezés (induláskor a mentett érték, setPageSize/setSort után az új) — ha
  // valamelyik nincs, az a mező egyszerűen kimarad.
  if (pageSize !== undefined) payload.page_size = pageSize
  if (sort !== undefined) payload.sort = sort

  return payload
}
