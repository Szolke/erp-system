import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useAuth } from '../contexts/AuthContext'
import { useToast } from '../contexts/ToastContext'
import { useTranslation } from '../contexts/TranslationContext'
import { listPreferences as listPreferencesApi } from '../api/listPreferences'

const SAVE_DEBOUNCE_MS = 600

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
 * Lapméret (`page_size`): opcionális, csak a lapozó listákon. A hívó a
 * `defaultPageSize`-ban adja meg a lista saját alapértékét (jellemzően 20, az
 * audit-naplón 50), az `urlPageSize`-ban pedig a URL-ben EXPLICIT módon
 * megadott értéket, ha volt ilyen. Feloldási sorrend: URL > mentett preferencia
 * > lista-default. A `defaultPageSize` elhagyásával a hook a `page_size` mezőt
 * ugyanúgy csak megőrzi, mint korábban (nem lapozó listák). A URL-érték
 * cégváltáskor ELÉVÜL — l. `lastMountedCompanyId`.
 *
 * @param {string} listKey
 * @param {Array<{key: string, default?: boolean, locked?: boolean, permission?: string}>} registry
 * @param {{defaultPageSize?: number|null, urlPageSize?: number|null}} [options]
 */
export function useListColumns(listKey, registry, options = {}) {
  const { defaultPageSize = null, urlPageSize = null } = options
  const { can, listPreferences, activeCompanyId } = useAuth()
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
  const savedRef = useRef(saved)
  const visibleKeysRef = useRef(visibleKeys)
  const orderedKeysRef = useRef(orderedKeys)
  const debounceRef = useRef(null)
  const companyRef = useRef(activeCompanyId)
  // A MENTENDŐ lapméret. Szándékosan külön él a megjelenített `pageSize`-tól:
  // egy megosztott link ?per_page=100 értéke a nézetet átállítja, de NEM írja
  // felül a user mentett preferenciáját egy későbbi oszlop-kapcsolgatáskor.
  const persistedPageSizeRef = useRef(saved?.page_size)

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
    if (companyRef.current !== activeCompanyId) {
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
      companyRef.current = activeCompanyId
      urlPageSizeRef.current = null
    }

    savedRef.current = saved
    setVisibleKeys(computeVisibleKeys(permittedColumns, saved))
    setOrderedKeys(computeOrderedKeys(permittedColumns, saved))
    persistedPageSizeRef.current = saved?.page_size
    setPageSizeState(resolvePageSize(urlPageSizeRef.current, saved, defaultPageSize))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listKey, saved, activeCompanyId])

  useEffect(() => () => clearTimeout(debounceRef.current), [])

  const scheduleSave = useCallback((nextVisibleKeys, nextOrderedKeys) => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(async () => {
      try {
        await listPreferencesApi.update(
          listKey,
          buildPayload(nextVisibleKeys, nextOrderedKeys, savedRef.current, persistedPageSizeRef.current),
        )
      } catch {
        // A helyi (optimista) állapotot szándékosan NEM görgetjük vissza — a
        // felhasználó választása a képernyőn érvényben marad, csak a
        // háttérben történő mentés esett ki; csendes, diszkrét jelzés.
        addToast(t('columns.save_error'), 'error')
      }
    }, SAVE_DEBOUNCE_MS)
  }, [listKey, addToast, t])

  const toggle = useCallback((key) => {
    const column = permittedColumns.find((col) => col.key === key)
    if (!column || column.locked) return

    setVisibleKeys((prev) => {
      const next = prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
      scheduleSave(next, orderedKeysRef.current)
      return next
    })
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

    setOrderedKeys((prev) => {
      const fromIndex = prev.indexOf(activeKey)
      const toIndex = prev.indexOf(overKey)
      if (fromIndex === -1 || toIndex === -1) return prev

      const next = enforceLockedPositions(moveKey(prev, fromIndex, toIndex), permittedColumns)
      scheduleSave(visibleKeysRef.current, next)
      return next
    })
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

  const reset = useCallback(async () => {
    clearTimeout(debounceRef.current)
    setVisibleKeys(defaultVisibleKeys(permittedColumns))
    setOrderedKeys(permittedColumns.map((col) => col.key))
    // A reset a TELJES preferencia-sort törli (DELETE), tehát a mentett
    // lapméret is elvész — a megjelenített értéket ezért vissza kell vinnünk a
    // mentés nélküli feloldásra (URL > lista-default), különben a képernyő és a
    // szerver állapota szétcsúszna a következő újratöltésig.
    persistedPageSizeRef.current = undefined
    setPageSizeState(resolvePageSize(urlPageSizeRef.current, null, defaultPageSize))
    try {
      await listPreferencesApi.remove(listKey)
    } catch {
      addToast(t('columns.save_error'), 'error')
    }
  }, [listKey, permittedColumns, defaultPageSize, addToast, t])

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

    return visibilityDirty || orderDirty
  }, [permittedColumns, visibleKeys, orderedKeys])

  return { allColumns, visibleColumns, isVisible, toggle, reorder, reset, isDirty, pageSize, setPageSize }
}

// URL > mentett preferencia > lista-default. A URL azért erősebb, mert egy
// megosztott link (?per_page=100) szándékos, egyszeri kérés — a mentett érték
// pedig a user "szokásos" beállítása, ami a link bezárása után visszaáll.
function resolvePageSize(urlPageSize, saved, defaultPageSize) {
  if (urlPageSize != null) return urlPageSize
  if (saved?.page_size != null) return saved.page_size
  return defaultPageSize
}

function defaultVisibleKeys(permittedColumns) {
  return permittedColumns.filter((col) => col.default || col.locked).map((col) => col.key)
}

function computeVisibleKeys(permittedColumns, saved) {
  const savedOrder = saved?.columns?.order ?? null
  const savedVisible = new Set(saved?.columns?.visible ?? [])

  return permittedColumns
    .filter((col) => {
      if (col.locked) return true
      if (!savedOrder) return col.default
      return savedOrder.includes(col.key) ? savedVisible.has(col.key) : col.default
    })
    .map((col) => col.key)
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

function buildPayload(visibleKeys, orderedKeys, saved, pageSize) {
  const payload = {
    columns: {
      visible: visibleKeys,
      order: orderedKeys,
    },
  }
  // A PUT teljes upsert, ezért a nem ezen a hívási úton keletkező mezőket is
  // vissza kell küldeni. A `pageSize` a mentendő lapméret (induláskor a mentett
  // érték, setPageSize után az új) — ha nincs ilyen, a mező kimarad. A `sort`-ot
  // ez a fázis még nem kezeli, azt változatlanul őrizzük meg.
  if (pageSize !== undefined) payload.page_size = pageSize
  if (saved?.sort !== undefined) payload.sort = saved.sort

  return payload
}
