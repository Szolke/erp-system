import { StrictMode } from 'react'
import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useListColumns, resetListColumnsCompanyMemory } from './useListColumns'
import { useAuth } from '../contexts/AuthContext'
import { useToast } from '../contexts/ToastContext'
import { useTranslation } from '../contexts/TranslationContext'
import { listPreferences as listPreferencesApi } from '../api/listPreferences'

vi.mock('../contexts/AuthContext', () => ({ useAuth: vi.fn() }))
vi.mock('../contexts/ToastContext', () => ({ useToast: vi.fn() }))
vi.mock('../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))
vi.mock('../api/listPreferences', () => ({
  listPreferences: { update: vi.fn(), remove: vi.fn() },
}))

const LIST_KEY = 'test-list'

// Registry-fixtures a frontend/src/columns/*.js valódi mintáira szabva.
const REG_STANDARD = [ // pl. users.js: elöl+hátul locked
  { key: 'name', default: true, locked: true },
  { key: 'email', default: true },
  { key: 'groups', default: true },
  { key: 'status', default: false },
  { key: 'actions', default: true, locked: true },
]

const REG_FRONT_ONLY = [ // pl. documents.js: nincs "actions" oszlop, csak elöl locked
  { key: 'number', default: true, locked: true },
  { key: 'partner', default: true },
  { key: 'issue_date', default: false },
]

const REG_SANDWICH = [ // pl. salesGroups.js: egyetlen mozgatható oszlop
  { key: 'display_name', default: true, locked: true },
  { key: 'name', default: true },
  { key: 'actions', default: true, locked: true },
]

const REG_ALL_LOCKED = [
  { key: 'a', default: true, locked: true },
  { key: 'b', default: true, locked: true },
]

const REG_WITH_PERMISSION = [
  { key: 'name', default: true, locked: true },
  { key: 'email', default: true },
  { key: 'secret', default: true, permission: 'secret.view' },
  { key: 'actions', default: true, locked: true },
]

// `strict: true` esetén a hook a valós alkalmazással azonos módon, StrictMode
// alatt fut (l. main.jsx) — a kettős render-futás így tesztelhető.
function setup(registry, { saved, can, options, companyId = 1, strict = false } = {}) {
  mockAuth({ saved, can, companyId })
  useToast.mockReturnValue(vi.fn())
  useTranslation.mockReturnValue({ t: (key) => key })
  return renderHook(
    () => useListColumns(LIST_KEY, registry, options),
    strict ? { wrapper: StrictMode } : undefined,
  )
}

// Cégváltás szimulálásához külön is hívható: a rerender() ezt az új /api/me
// állapotot fogja látni.
function mockAuth({ saved, can, companyId = 1 } = {}) {
  useAuth.mockReturnValue({
    can: can ?? (() => true),
    listPreferences: saved === undefined ? {} : { [LIST_KEY]: saved },
    activeCompanyId: companyId,
  })
}

beforeEach(() => {
  vi.useFakeTimers()
  // A hook modul-szintű cég-memóriája túlélné a teszteseteket, és egy korábbi
  // eset cégváltása hamis "elévülést" okozna a következőben.
  resetListColumnsCompanyMemory()
  listPreferencesApi.update.mockReset().mockResolvedValue({})
  listPreferencesApi.remove.mockReset().mockResolvedValue({})
})

afterEach(() => {
  vi.useRealTimers()
  vi.clearAllMocks()
})

describe('láthatóság-merge (computeVisibleKeys)', () => {
  it('mentett preferencia nélkül a registry defaultjai érvényesek', () => {
    const { result } = setup(REG_STANDARD)
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'actions'])
  })

  it('columns nélküli mentett preferencia (pl. csak page_size) esetén is a defaultok érvényesek', () => {
    const { result } = setup(REG_STANDARD, { saved: { page_size: 25 } })
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'actions'])
    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'status', 'actions'])
  })

  it('a mentésből hiányzó (újonnan bevezetett) oszlop a saját default-ja szerint jelenik meg', () => {
    const saved = { columns: { order: ['name', 'email', 'actions'], visible: ['name', 'email', 'actions'] } }
    const { result } = setup(REG_STANDARD, { saved })
    // 'groups' (default:true) és 'status' (default:false) nincs benne a mentett order-ben
    expect(result.current.isVisible('groups')).toBe(true)
    expect(result.current.isVisible('status')).toBe(false)
  })

  it('a mentésben szereplő, de a kódból azóta törölt oszlopkulcs kiesik', () => {
    const saved = {
      columns: {
        order: ['name', 'email', 'legacy_field', 'actions'],
        visible: ['name', 'email', 'legacy_field', 'actions'],
      },
    }
    const { result } = setup(REG_STANDARD, { saved })
    expect(result.current.allColumns.map((c) => c.key)).not.toContain('legacy_field')
  })

  it('a locked oszlop mindig látható, akkor is, ha a mentett preferencia elrejtené', () => {
    const saved = { columns: { order: ['name', 'email', 'actions'], visible: ['email'] } }
    const { result } = setup(REG_STANDARD, { saved })
    expect(result.current.isVisible('name')).toBe(true)
    expect(result.current.isVisible('actions')).toBe(true)
  })

  it('permission-höz kötött oszlop kiesik, ha a usernek nincs joga', () => {
    const { result } = setup(REG_WITH_PERMISSION, { can: (key) => key !== 'secret.view' })
    expect(result.current.allColumns.map((c) => c.key)).not.toContain('secret')
  })

  it('cégváltáskor a helyben beállított láthatóság is eldobódik, ha egyik cégnek sincs mentett sora', () => {
    // Ugyanaz a hibaminta, mint a lapméretnél: `saved` mindkét oldalon
    // undefined, tehát csak az aktív cég azonosítója jelzi a váltást.
    const { result, rerender } = setup(REG_STANDARD)
    act(() => result.current.toggle('status'))
    expect(result.current.isVisible('status')).toBe(true)

    mockAuth({ companyId: 2 })
    rerender()

    expect(result.current.isVisible('status')).toBe(false)
  })

  it('permission-höz kötött oszlop megjelenik, ha a usernek van joga', () => {
    const { result } = setup(REG_WITH_PERMISSION, { can: () => true })
    expect(result.current.allColumns.map((c) => c.key)).toContain('secret')
  })
})

describe('sorrend betöltéskor (computeOrderedKeys + enforceLockedPositions)', () => {
  it('a columns.order szerinti sorrendben jönnek vissza az oszlopok, nem a registry sorrendjében', () => {
    const saved = {
      columns: {
        order: ['actions', 'status', 'groups', 'email', 'name'],
        visible: ['name', 'email', 'groups', 'status', 'actions'],
      },
    }
    const { result } = setup(REG_STANDARD, { saved })
    // a locked 'name'/'actions' a kitűzött pozícióba kényszerül, a köztes rész megtartja a mentett sorrendet
    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'status', 'groups', 'email', 'actions'])
  })

  it('az order-ben nem szereplő új kulcs a záró locked oszlop elé, registry-sorrendben kerül', () => {
    const saved = { columns: { order: ['name', 'email', 'actions'], visible: ['name', 'email', 'actions'] } }
    const { result } = setup(REG_STANDARD, { saved })
    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'status', 'actions'])
  })

  it('záró locked oszlop hiányában (nincs "actions") is az elülső locked pozíció kényszerített', () => {
    const saved = { columns: { order: ['issue_date', 'partner', 'number'], visible: ['issue_date', 'partner', 'number'] } }
    const { result } = setup(REG_FRONT_ONLY, { saved })
    expect(result.current.allColumns.map((c) => c.key)).toEqual(['number', 'issue_date', 'partner'])
  })

  it('minden oszlop locked esetén az order mindig a registry-sorrendet követi, a mentett order-től függetlenül', () => {
    const saved = { columns: { order: ['b', 'a'], visible: ['a', 'b'] } }
    const { result } = setup(REG_ALL_LOCKED, { saved })
    expect(result.current.allColumns.map((c) => c.key)).toEqual(['a', 'b'])
  })
})

describe('reorder', () => {
  it('nem-locked oszlopok közti mozgatás után a locked-kényszerítés is érvényesül', () => {
    const { result } = setup(REG_STANDARD)
    act(() => result.current.reorder('email', 'groups'))
    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'groups', 'email', 'status', 'actions'])
  })

  it('locked oszlopra (mint active vagy over) a reorder no-op', async () => {
    const { result } = setup(REG_STANDARD)
    const before = result.current.allColumns.map((c) => c.key)

    act(() => result.current.reorder('name', 'email'))
    expect(result.current.allColumns.map((c) => c.key)).toEqual(before)

    act(() => result.current.reorder('email', 'actions'))
    expect(result.current.allColumns.map((c) => c.key)).toEqual(before)

    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(listPreferencesApi.update).not.toHaveBeenCalled()
  })

  it('egyetlen mozgatható oszlop esetén a reorder soha nem hoz létre változást', () => {
    const { result } = setup(REG_SANDWICH)
    const before = result.current.allColumns.map((c) => c.key)

    act(() => result.current.reorder('name', 'name')) // activeKey === overKey
    act(() => result.current.reorder('name', 'display_name')) // overColumn locked
    act(() => result.current.reorder('actions', 'name')) // activeColumn locked

    expect(result.current.allColumns.map((c) => c.key)).toEqual(before)
  })
})

describe('toggle', () => {
  it('locked oszlopra a toggle no-op, mentés sem indul', async () => {
    const { result } = setup(REG_STANDARD)
    act(() => result.current.toggle('name'))
    expect(result.current.isVisible('name')).toBe(true)

    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(listPreferencesApi.update).not.toHaveBeenCalled()
  })

  it('nem-locked oszlop láthatósága átváltható', () => {
    const { result } = setup(REG_STANDARD)
    expect(result.current.isVisible('status')).toBe(false)

    act(() => result.current.toggle('status'))
    expect(result.current.isVisible('status')).toBe(true)

    act(() => result.current.toggle('status'))
    expect(result.current.isVisible('status')).toBe(false)
  })
})

describe('reset', () => {
  it('a láthatóságot és a sorrendet is visszaállítja a registry defaultjaira', async () => {
    const saved = {
      columns: {
        order: ['actions', 'status', 'groups', 'email', 'name'],
        visible: ['email'],
      },
    }
    const { result } = setup(REG_STANDARD, { saved })
    // induláskor a sorrend eltér a defaulttól (l. a fenti "columns.order szerinti sorrend" teszt)
    expect(result.current.allColumns.map((c) => c.key)).not.toEqual(['name', 'email', 'groups', 'status', 'actions'])

    await act(async () => { await result.current.reset() })

    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'status', 'actions'])
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'actions'])
    expect(listPreferencesApi.remove).toHaveBeenCalledWith(LIST_KEY)
  })
})

describe('mentés — debounce-olt PUT payload', () => {
  it('toggle után a helyes payloadot küldi; visible tömb a TOGGLE-sorrendet követi, nem az oszlop-sorrendet', async () => {
    const { result } = setup(REG_STANDARD)
    act(() => result.current.toggle('status'))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

    expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
    const [key, payload] = listPreferencesApi.update.mock.calls[0]
    expect(key).toBe(LIST_KEY)
    // A hook a `visible` tömböt appendeléssel építi (prev + [key]), NEM az
    // oszlop-sorrend szerint rendezi újra — 'status' ezért a lista VÉGÉN, az
    // 'actions' UTÁN jelenik meg, holott az oszlop-sorrendben 'status' előtte áll.
    expect(payload).toEqual({
      columns: {
        visible: ['name', 'email', 'groups', 'actions', 'status'],
        order: ['name', 'email', 'groups', 'status', 'actions'],
      },
    })
  })

  it('oszlop-kapcsolás nem írja felül a mentett page_size/sort mezőket', async () => {
    const saved = {
      columns: { order: ['name', 'email', 'groups', 'status', 'actions'], visible: ['name', 'email', 'groups', 'actions'] },
      page_size: 50,
      sort: '-created_at',
    }
    const { result } = setup(REG_STANDARD, { saved })
    act(() => result.current.toggle('status'))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

    const [, payload] = listPreferencesApi.update.mock.calls[0]
    expect(payload.page_size).toBe(50)
    expect(payload.sort).toBe('-created_at')
  })

  it('ha nincs mentett page_size/sort, a payload sem tartalmazza azokat', async () => {
    const { result } = setup(REG_STANDARD)
    act(() => result.current.toggle('status'))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

    const [, payload] = listPreferencesApi.update.mock.calls[0]
    expect(payload).not.toHaveProperty('page_size')
    expect(payload).not.toHaveProperty('sort')
  })

  it('reorder is elindítja a mentést, a friss (locked-kényszerített) order-rel', async () => {
    const { result } = setup(REG_STANDARD)
    act(() => result.current.reorder('email', 'groups'))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

    const [, payload] = listPreferencesApi.update.mock.calls[0]
    expect(payload.columns.order).toEqual(result.current.allColumns.map((c) => c.key))
  })
})

describe('lapméret (page_size)', () => {
  const PAGED = { defaultPageSize: 20 }

  describe('feloldás: URL > mentett > default', () => {
    it('mentett érték nélkül a lista defaultja érvényes', () => {
      const { result } = setup(REG_STANDARD, { options: PAGED })
      expect(result.current.pageSize).toBe(20)
    })

    it('a mentett page_size erősebb a lista defaultjánál', () => {
      const { result } = setup(REG_STANDARD, { saved: { page_size: 100 }, options: PAGED })
      expect(result.current.pageSize).toBe(100)
    })

    it('csak oszlopokat tartalmazó mentés (page_size nélkül) esetén a lista defaultja érvényes', () => {
      // A `saved` sor LÉTEZIK, csak a `page_size` mezője hiányzik belőle — az
      // oszlopválasztó előtti mentések pont ilyenek. A feloldásnak ilyenkor
      // tovább kell esnie a lista defaultjára, nem `undefined`-ot adni.
      const { result } = setup(REG_STANDARD, {
        saved: { columns: { visible: ['name', 'email', 'actions'], order: ['name', 'email', 'groups', 'status', 'actions'] } },
        options: PAGED,
      })
      expect(result.current.pageSize).toBe(20)
    })

    it('a URL-beli lapméret mentett sor nélkül is erősebb a lista defaultjánál', () => {
      const { result } = setup(REG_STANDARD, { options: { defaultPageSize: 20, urlPageSize: 200 } })
      expect(result.current.pageSize).toBe(200)
    })

    it('az EXPLICIT URL-beli lapméret erősebb a mentettnél (megosztott link)', () => {
      const { result } = setup(REG_STANDARD, {
        saved: { page_size: 100 },
        options: { defaultPageSize: 20, urlPageSize: 500 },
      })
      expect(result.current.pageSize).toBe(500)
    })

    it('defaultPageSize nélkül (nem lapozó lista) nincs saját lapméret', () => {
      // A hook ilyenkor csak megőrzi a mentett page_size-t (l. a mentés-blokk
      // megőrző tesztjét), de nem ad vissza megjeleníthető értéket.
      const { result } = setup(REG_STANDARD)
      expect(result.current.pageSize).toBe(null)
    })

    it('cégváltáskor (új mentett preferencia) a másik cég lapmérete lép életbe', () => {
      const { result, rerender } = setup(REG_STANDARD, { saved: { page_size: 50 }, options: PAGED })
      expect(result.current.pageSize).toBe(50)

      mockAuth({ saved: { page_size: 200 }, companyId: 2 })
      rerender()

      expect(result.current.pageSize).toBe(200)
    })

    it('cégváltáskor akkor is a defaultra áll, ha EGYIK cégnek sincs mentett sora', () => {
      // Ilyenkor a `saved` mindkét oldalon undefined, tehát a puszta
      // referencia-változás nem jelezné a váltást — az előző cégen beállított
      // lapméret bennragadna a legördülőben.
      const { result, rerender } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(100))
      expect(result.current.pageSize).toBe(100)

      mockAuth({ companyId: 2 })
      rerender()

      expect(result.current.pageSize).toBe(20)
    })

    it('cégváltáskor a megnyitáskori URL-beli lapméret is elévül', () => {
      // A listaoldal a tényleges lapméretet visszaírja a címsorba, ezért egy
      // újratöltés után a saját magunk írta ?per_page=… is "explicitnek"
      // látszik — ha ez túlélné a cégváltást, a másik cég preferenciája sosem
      // érvényesülne (ez volt a Bizonylatok listáján tapasztalt hiba).
      const { result, rerender } = setup(REG_STANDARD, {
        saved: { page_size: 100 },
        options: { defaultPageSize: 20, urlPageSize: 100 },
      })
      expect(result.current.pageSize).toBe(100)

      mockAuth({ saved: { page_size: 50 }, companyId: 2 })
      rerender()

      expect(result.current.pageSize).toBe(50)
    })

    it('cégváltáskor a URL-érték a másik cég mentett sora nélkül is elévül', () => {
      const { result, rerender } = setup(REG_STANDARD, {
        saved: { page_size: 100 },
        options: { defaultPageSize: 20, urlPageSize: 100 },
      })

      mockAuth({ companyId: 2 })
      rerender()

      expect(result.current.pageSize).toBe(20)
    })

    it('cégváltás miatti ÚJRACSATOLÁSKOR is elévül a címsorban maradt lapméret', () => {
      // A valóságban a cégváltás nem re-renderel, hanem ÚJRACSATOL: a Layout
      // `<Outlet key={activeCompanyId} />`-je új példányt hoz létre, tehát a
      // hook refjei elvesznek. A címsor viszont megmarad, benne az előző cég
      // lapméretével — ezt a mount nem olvashatja megosztott linknek.
      const first = setup(REG_STANDARD, {
        saved: { page_size: 100 },
        options: { defaultPageSize: 20, urlPageSize: 100 },
      })
      expect(first.result.current.pageSize).toBe(100)
      first.unmount()

      const second = setup(REG_STANDARD, {
        saved: { page_size: 50 },
        companyId: 2,
        options: { defaultPageSize: 20, urlPageSize: 100 },
      })

      expect(second.result.current.pageSize).toBe(50)
    })

    it('újracsatoláskor a mentett sor nélküli cég is a saját defaultjára áll', () => {
      const first = setup(REG_STANDARD, {
        saved: { page_size: 100 },
        options: { defaultPageSize: 20, urlPageSize: 100 },
      })
      first.unmount()

      const second = setup(REG_STANDARD, {
        companyId: 2,
        options: { defaultPageSize: 20, urlPageSize: 100 },
      })

      expect(second.result.current.pageSize).toBe(20)
    })

    it('UGYANAZON a cégen belüli új mount (navigálás) esetén a URL-érték érvényben marad', () => {
      // Az elévülés kizárólag a cégváltáshoz kötődik: egy megosztott link
      // megnyitása a lista elhagyása és visszatérése után is érvényes kérés.
      const first = setup(REG_STANDARD, { saved: { page_size: 100 }, options: PAGED })
      first.unmount()

      const second = setup(REG_STANDARD, {
        saved: { page_size: 100 },
        options: { defaultPageSize: 20, urlPageSize: 500 },
      })

      expect(second.result.current.pageSize).toBe(500)
    })

    it('cégváltás eldobja a még ki nem ment mentést (az már a másik cég sorába íródna)', async () => {
      const { result, rerender } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(100))

      mockAuth({ companyId: 2 })
      rerender()
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      expect(listPreferencesApi.update).not.toHaveBeenCalled()
    })
  })

  describe('setPageSize — mentés', () => {
    it('a selector-váltás a mentendő payloadba írja a page_size-t, az oszlopokkal együtt', async () => {
      const { result } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(100))
      expect(result.current.pageSize).toBe(100)

      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
      const [key, payload] = listPreferencesApi.update.mock.calls[0]
      expect(key).toBe(LIST_KEY)
      expect(payload.page_size).toBe(100)
      // A láthatóság/sorrend mentése nem sérülhet: ugyanabba a PUT-ba megy.
      expect(payload.columns).toEqual({
        visible: ['name', 'email', 'groups', 'actions'],
        order: ['name', 'email', 'groups', 'status', 'actions'],
      })
    })

    it('a lapméret-váltás után egy oszlop-kapcsolás is az ÚJ lapmérettel ment', async () => {
      const { result } = setup(REG_STANDARD, { saved: { page_size: 50 }, options: PAGED })
      act(() => result.current.setPageSize(200))
      act(() => result.current.toggle('status'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      const calls = listPreferencesApi.update.mock.calls
      expect(calls[calls.length - 1][1].page_size).toBe(200)
    })

    it('URL-ből jövő lapméret esetén az oszlop-kapcsolás NEM írja felül a mentett értéket', async () => {
      // Egy megosztott link (?per_page=500) csak a nézetet állítja át; a user
      // saját, mentett lapmérete csak akkor változhat, ha ő maga választ újat.
      const { result } = setup(REG_STANDARD, {
        saved: { page_size: 50 },
        options: { defaultPageSize: 20, urlPageSize: 500 },
      })
      act(() => result.current.toggle('status'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      const [, payload] = listPreferencesApi.update.mock.calls[0]
      expect(payload.page_size).toBe(50)
    })

    it('érvénytelen értékre no-op (nem állít állapotot, nem ment)', async () => {
      const { result } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(0))
      act(() => result.current.setPageSize('abc'))

      expect(result.current.pageSize).toBe(20)
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
      expect(listPreferencesApi.update).not.toHaveBeenCalled()
    })
  })

  describe('reset', () => {
    it('a lapméretet is visszaviszi a lista defaultjára (a DELETE a page_size-t is törli)', async () => {
      const { result } = setup(REG_STANDARD, { saved: { page_size: 200 }, options: PAGED })
      expect(result.current.pageSize).toBe(200)

      await act(async () => { await result.current.reset() })

      expect(result.current.pageSize).toBe(20)
      expect(listPreferencesApi.remove).toHaveBeenCalledWith(LIST_KEY)
    })

    it('explicit URL-beli lapméret esetén a reset a URL értékére áll vissza, nem a defaultra', async () => {
      const { result } = setup(REG_STANDARD, {
        saved: { page_size: 200 },
        options: { defaultPageSize: 20, urlPageSize: 100 },
      })

      await act(async () => { await result.current.reset() })

      expect(result.current.pageSize).toBe(100)
    })

    it('reset után egy oszlop-kapcsolás már nem küld page_size mezőt', async () => {
      const { result } = setup(REG_STANDARD, { saved: { page_size: 200 }, options: PAGED })
      await act(async () => { await result.current.reset() })

      act(() => result.current.toggle('status'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      const [, payload] = listPreferencesApi.update.mock.calls[0]
      expect(payload).not.toHaveProperty('page_size')
    })
  })

  describe('elévülés — első mount és StrictMode', () => {
    it('a legelső listamounton (üres cég-memória) a URL-érték érvényesül', () => {
      // A modul-szintű `lastMountedCompanyId` őre: `null` esetén nincs "előző
      // cég", tehát a címsorban álló érték csak megosztott linkből származhat —
      // ilyenkor nem szabad elévültnek tekinteni.
      const { result } = setup(REG_STANDARD, {
        saved: { page_size: 50 },
        options: { defaultPageSize: 20, urlPageSize: 500 },
      })
      expect(result.current.pageSize).toBe(500)
    })

    it('StrictMode alatt is a URL-érték érvényesül a saját cégen', () => {
      // A valós alkalmazás StrictMode-ban fut (main.jsx), tehát a render-test
      // kétszer hívódik meg — ez az eset azt rögzíti, hogy a kettős render nem
      // változtat a feloldás eredményén.
      //
      // FIGYELEM, amit ez NEM őriz: a hook kommentje szerint a cég-memóriát
      // azért csak effektben írjuk, mert render-fázisú írásnál a második
      // render-futás a saját írását olvasná vissza. Mutációs próbával
      // ellenőrizve: az írás render-fázisba mozgatásától egyik eset sem bukik
      // el, mert a StrictMode az ELSŐ render-futás useState-eredményét tartja
      // meg, tehát a második futás olvasása nem jut érvényre. A hook kommentje
      // így is helyes elővigyázatosság, de nincs mögötte fogó teszt.
      const { result } = setup(REG_STANDARD, {
        saved: { page_size: 50 },
        options: { defaultPageSize: 20, urlPageSize: 500 },
        strict: true,
      })
      expect(result.current.pageSize).toBe(500)
    })

    it('StrictMode alatt is elévül a cégváltás miatti újracsatoláskor a címsorban maradt érték', () => {
      const first = setup(REG_STANDARD, {
        saved: { page_size: 100 },
        options: { defaultPageSize: 20, urlPageSize: 100 },
        strict: true,
      })
      expect(first.result.current.pageSize).toBe(100)
      first.unmount()

      const second = setup(REG_STANDARD, {
        saved: { page_size: 50 },
        companyId: 2,
        options: { defaultPageSize: 20, urlPageSize: 100 },
        strict: true,
      })

      expect(second.result.current.pageSize).toBe(50)
    })
  })

  describe('debounce-ablak (600 ms)', () => {
    it('a 600 ms letelte ELŐTT nem megy ki PUT, utána pontosan egy', async () => {
      const { result } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(100))

      await act(async () => { await vi.advanceTimersByTimeAsync(599) })
      expect(listPreferencesApi.update).not.toHaveBeenCalled()

      await act(async () => { await vi.advanceTimersByTimeAsync(1) })
      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
      expect(listPreferencesApi.update.mock.calls[0][1].page_size).toBe(100)
    })

    it('a gyors egymás utáni lapméret-váltások EGYETLEN PUT-ba olvadnak, az utolsó értékkel', async () => {
      // A legördülőben kapkodó felhasználó nem küldhet három kérést a szerverre.
      const { result } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(50))
      await act(async () => { await vi.advanceTimersByTimeAsync(300) })
      act(() => result.current.setPageSize(100))
      await act(async () => { await vi.advanceTimersByTimeAsync(300) })
      act(() => result.current.setPageSize(200))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
      expect(listPreferencesApi.update.mock.calls[0][1].page_size).toBe(200)
    })

    it('a lapméret-váltást követő oszlop-kapcsolás újraindítja az ablakot (összesen egy PUT)', async () => {
      // A két művelet ugyanabba a debounce-ablakba esik, és ugyanabba a
      // preferencia-sorba ment — nem szabad két külön PUT-ot indítaniuk.
      const { result } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(200))
      await act(async () => { await vi.advanceTimersByTimeAsync(300) })
      act(() => result.current.toggle('status'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
      const [, payload] = listPreferencesApi.update.mock.calls[0]
      expect(payload.page_size).toBe(200)
      expect(payload.columns.visible).toContain('status')
    })

    it('az ablakon belüli lecsatolás eldobja a még ki nem ment lapméret-mentést', async () => {
      // A lista elhagyása a debounce-ablakon belül: a hook takarító-effektje
      // törli az időzítőt, különben egy már lecsatolt komponens írna a szerverre.
      const { result, unmount } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(100))
      unmount()
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      expect(listPreferencesApi.update).not.toHaveBeenCalled()
    })
  })
})
