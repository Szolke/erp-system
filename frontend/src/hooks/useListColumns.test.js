import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useListColumns } from './useListColumns'
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

function setup(registry, { saved, can } = {}) {
  useAuth.mockReturnValue({
    can: can ?? (() => true),
    listPreferences: saved === undefined ? {} : { [LIST_KEY]: saved },
  })
  useToast.mockReturnValue(vi.fn())
  useTranslation.mockReturnValue({ t: (key) => key })
  return renderHook(() => useListColumns(LIST_KEY, registry))
}

beforeEach(() => {
  vi.useFakeTimers()
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

  it('megőrzi a mentett page_size/sort mezőket, amiket ez a hook nem kezel', async () => {
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
