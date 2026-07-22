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
 * örökre láthatatlan a régi usereknek. Sorrendezés (drag&drop) ebben a
 * fázisban nincs, ezért `order` mentéskor mindig a teljes, jogosultság szerint
 * szűrt regisztry-sorrendet kapja — ez válik a következő betöltés "ismert
 * kulcsok" listájává.
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
  const savedRef = useRef(saved)
  const debounceRef = useRef(null)

  // Az /api/me újratöltésekor (induláskor, cégváltáskor) a helyi állapot
  // eldobódik és a frissen betöltött mentett preferenciából épül újra — a
  // puszta toggle() hívás viszont NEM változtatja meg a `saved` referenciát,
  // tehát ez a hatás toggle közben nem fut le feleslegesen.
  useEffect(() => {
    savedRef.current = saved
    setVisibleKeys(computeVisibleKeys(permittedColumns, saved))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listKey, saved])

  useEffect(() => () => clearTimeout(debounceRef.current), [])

  const scheduleSave = useCallback((nextVisibleKeys) => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(async () => {
      try {
        await listPreferencesApi.update(listKey, buildPayload(nextVisibleKeys, permittedColumns, savedRef.current))
      } catch {
        // A helyi (optimista) állapotot szándékosan NEM görgetjük vissza — a
        // felhasználó választása a képernyőn érvényben marad, csak a
        // háttérben történő mentés esett ki; csendes, diszkrét jelzés.
        addToast(t('columns.save_error'), 'error')
      }
    }, SAVE_DEBOUNCE_MS)
  }, [listKey, permittedColumns, addToast, t])

  const toggle = useCallback((key) => {
    const column = permittedColumns.find((col) => col.key === key)
    if (!column || column.locked) return

    setVisibleKeys((prev) => {
      const next = prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
      scheduleSave(next)
      return next
    })
  }, [permittedColumns, scheduleSave])

  const reset = useCallback(async () => {
    clearTimeout(debounceRef.current)
    setVisibleKeys(defaultVisibleKeys(permittedColumns))
    try {
      await listPreferencesApi.remove(listKey)
    } catch {
      addToast(t('columns.save_error'), 'error')
    }
  }, [listKey, permittedColumns, addToast, t])

  const isVisible = useCallback((key) => visibleKeys.includes(key), [visibleKeys])

  const visibleColumns = useMemo(
    () => permittedColumns.filter((col) => visibleKeys.includes(col.key)),
    [permittedColumns, visibleKeys]
  )

  const isDirty = useMemo(() => {
    const defaults = defaultVisibleKeys(permittedColumns)
    return defaults.length !== visibleKeys.length || !defaults.every((k) => visibleKeys.includes(k))
  }, [permittedColumns, visibleKeys])

  return { allColumns: permittedColumns, visibleColumns, isVisible, toggle, reset, isDirty }
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

function buildPayload(visibleKeys, permittedColumns, saved) {
  const payload = {
    columns: {
      visible: visibleKeys,
      order: permittedColumns.map((col) => col.key),
    },
  }
  // page_size/sort mezőket ez a fázis nem kezeli — ha volt mentett érték,
  // változatlanul visszaküldjük, hogy a PUT (teljes upsert) ne írja felül őket.
  if (saved?.page_size !== undefined) payload.page_size = saved.page_size
  if (saved?.sort !== undefined) payload.sort = saved.sort

  return payload
}
