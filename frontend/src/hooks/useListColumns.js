import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useAuth } from '../contexts/AuthContext'
import { useToast } from '../contexts/ToastContext'
import { useTranslation } from '../contexts/TranslationContext'
import { listPreferences as listPreferencesApi } from '../api/listPreferences'

const SAVE_DEBOUNCE_MS = 600

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
 */
export function useListColumns(listKey, registry) {
  const { can, listPreferences } = useAuth()
  const { t } = useTranslation()
  const addToast = useToast()

  const permittedColumns = useMemo(
    () => registry.filter((col) => !col.permission || can(col.permission)),
    [registry, can]
  )

  const saved = listPreferences?.[listKey]

  const [visibleKeys, setVisibleKeys] = useState(() => computeVisibleKeys(permittedColumns, saved))
  const [orderedKeys, setOrderedKeys] = useState(() => computeOrderedKeys(permittedColumns, saved))
  const savedRef = useRef(saved)
  const visibleKeysRef = useRef(visibleKeys)
  const orderedKeysRef = useRef(orderedKeys)
  const debounceRef = useRef(null)

  useEffect(() => { visibleKeysRef.current = visibleKeys }, [visibleKeys])
  useEffect(() => { orderedKeysRef.current = orderedKeys }, [orderedKeys])

  // Az /api/me újratöltésekor (induláskor, cégváltáskor) a helyi állapot
  // eldobódik és a frissen betöltött mentett preferenciából épül újra — a
  // puszta toggle()/reorder() hívás viszont NEM változtatja meg a `saved`
  // referenciát, tehát ez a hatás azok közben nem fut le feleslegesen.
  useEffect(() => {
    savedRef.current = saved
    setVisibleKeys(computeVisibleKeys(permittedColumns, saved))
    setOrderedKeys(computeOrderedKeys(permittedColumns, saved))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listKey, saved])

  useEffect(() => () => clearTimeout(debounceRef.current), [])

  const scheduleSave = useCallback((nextVisibleKeys, nextOrderedKeys) => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(async () => {
      try {
        await listPreferencesApi.update(listKey, buildPayload(nextVisibleKeys, nextOrderedKeys, savedRef.current))
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

  const reset = useCallback(async () => {
    clearTimeout(debounceRef.current)
    setVisibleKeys(defaultVisibleKeys(permittedColumns))
    setOrderedKeys(permittedColumns.map((col) => col.key))
    try {
      await listPreferencesApi.remove(listKey)
    } catch {
      addToast(t('columns.save_error'), 'error')
    }
  }, [listKey, permittedColumns, addToast, t])

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

  return { allColumns, visibleColumns, isVisible, toggle, reorder, reset, isDirty }
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

function buildPayload(visibleKeys, orderedKeys, saved) {
  const payload = {
    columns: {
      visible: visibleKeys,
      order: orderedKeys,
    },
  }
  // page_size/sort mezőket ez a fázis nem kezeli — ha volt mentett érték,
  // változatlanul visszaküldjük, hogy a PUT (teljes upsert) ne írja felül őket.
  if (saved?.page_size !== undefined) payload.page_size = saved.page_size
  if (saved?.sort !== undefined) payload.sort = saved.sort

  return payload
}
