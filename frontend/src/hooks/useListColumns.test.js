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

// Locked oszlop NÉLKÜLI registry: ma egyetlen valós lista sem ilyen (mindegyik
// registryben van legalább egy `locked` oszlop), ezért a minimum-oszlop guard
// csak ezen a fixture-ön mérhető. Pontosan ez a sentinel értelme: ha egyszer
// bekerül egy locked oszlop nélküli lista, a szabály már készen áll.
const REG_NO_LOCKED = [
  { key: 'code', default: true },
  { key: 'name', default: true },
  { key: 'note', default: false },
]

// Rendezhető registry a Bizonylatok listájának mintájára: vegyesen alapból
// növekvő (szöveges) és alapból csökkenő (dátum/összeg) első irányú oszlopok,
// plusz egy szándékosan NEM rendezhető oszlop.
const REG_SORTABLE = [
  { key: 'number', default: true, locked: true, sortable: true },
  { key: 'partner', default: true, sortable: true },
  { key: 'issue_date', default: true, sortable: true, sortInitialDir: 'desc' },
  { key: 'note', default: false },
]

const REG_WITH_PERMISSION = [
  { key: 'name', default: true, locked: true },
  { key: 'email', default: true },
  { key: 'secret', default: true, permission: 'secret.view' },
  { key: 'actions', default: true, locked: true },
]

// `strict: true` esetén a hook a valós alkalmazással azonos módon, StrictMode
// alatt fut (l. main.jsx) — a kettős render-futás így tesztelhető.
// A visszaadott `toast` a hook által ténylegesen hívott addToast — a mentési
// hibaút ezen keresztül ellenőrizhető anélkül, hogy a modul-mockhoz kellene nyúlni.
function setup(registry, { saved, can, options, companyId = 1, strict = false, live = false } = {}) {
  // `live: true` esetén a hívó már beállította a useAuth-mockot (l. createLiveAuth),
  // azt itt nem szabad felülírni.
  if (!live) mockAuth({ saved, can, companyId })
  const toast = vi.fn()
  useToast.mockReturnValue(toast)
  useTranslation.mockReturnValue({ t: (key) => key })
  const utils = renderHook(
    () => useListColumns(LIST_KEY, registry, options),
    strict ? { wrapper: StrictMode } : undefined,
  )
  return { ...utils, toast }
}

// Cégváltás szimulálásához külön is hívható: a rerender() ezt az új /api/me
// állapotot fogja látni.
function mockAuth({ saved, can, companyId = 1 } = {}) {
  useAuth.mockReturnValue({
    can: can ?? (() => true),
    listPreferences: saved === undefined ? {} : { [LIST_KEY]: saved },
    activeCompanyId: companyId,
    // Ebben a harnessben a context-írás NEM hat vissza a hookra (a `saved` minden
    // renderen frissen injektált konstans) — az optimista írás átfogó, valódi
    // esetét a lenti `createLiveAuth` harness fedi.
    mergeListPreference: vi.fn(),
    clearListPreference: vi.fn(),
  })
}

// Élő AuthContext-utánzat: a merge/clear setterek TÉNYLEGESEN írják a megosztott
// listPreferences objektumot — immutábilisan, ahogy az AuthProvider is (l.
// AuthContext.test.jsx). Csak így modellezhető a valódi hiba: a mentés a
// contextbe is átmegy, ezért egy LECSATOLÁS + ÚJRACSATOLÁS (route-váltás oda-vissza)
// a szűkített oszlophalmazt kapja vissza, nem az /api/me betöltéskori pillanatképét.
function createLiveAuth({ companyId = 1, initial = {} } = {}) {
  const store = { listPreferences: initial, companyId }

  const apply = () => {
    useAuth.mockReturnValue({
      can: () => true,
      listPreferences: store.listPreferences,
      activeCompanyId: store.companyId,
      mergeListPreference: (key, preferences) => {
        store.listPreferences = { ...store.listPreferences, [key]: preferences }
        apply()
      },
      clearListPreference: (key) => {
        if (!(key in store.listPreferences)) return
        const next = { ...store.listPreferences }
        delete next[key]
        store.listPreferences = next
        apply()
      },
    })
  }

  apply()
  return store
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

describe('minimum-oszlop guard (MIN_VISIBLE_COLUMNS)', () => {
  it('az utolsó látható oszlop nem rejthető el, és mentés sem indul', async () => {
    // A guard nélkül ez a lista NULLA látható oszlopra redukálódna: nincs benne
    // locked oszlop, ami eleve elrejthetetlen lenne.
    const { result } = setup(REG_NO_LOCKED)
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['code', 'name'])

    act(() => result.current.toggle('name'))
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['code'])

    // Az utolsó megmaradt oszlop kikapcsolása no-op — a helyi állapot marad…
    act(() => result.current.toggle('code'))
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['code'])

    // …és a szerverre sem megy ki róla PUT (csak az előző, jogos toggle-é).
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
    expect(listPreferencesApi.update.mock.calls[0][1].columns.visible).toEqual(['code'])
  })

  it('nincs kitüntetett kötelező oszlop: bármelyik lehet az utolsó megmaradó', () => {
    // Ugyanaz a lista, fordított sorrendben lekapcsolva: itt a 'name' marad
    // utolsóként, és most az válik elrejthetetlenné.
    const { result } = setup(REG_NO_LOCKED)
    act(() => result.current.toggle('code'))
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['name'])

    act(() => result.current.toggle('name'))
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['name'])
  })

  it('az utolsó oszlop visszakapcsolás után újra elrejthető', () => {
    // A guard nem "ragad be": amint van másik látható oszlop, a korlátozás megszűnik.
    const { result } = setup(REG_NO_LOCKED)
    act(() => result.current.toggle('name'))
    act(() => result.current.toggle('note'))
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['code', 'note'])

    act(() => result.current.toggle('code'))
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['note'])
  })

  it('a mindent elrejtő MENTETT preferencia sem üríti ki a listát (betöltési út)', () => {
    // Ilyen sor ma nem keletkezhet a ColumnPickerből, de közvetlen API-hívással
    // igen — a backend a `visible` tömböt nem validálja. A fail-safe az első
    // registry-oszlopot tartja láthatóan.
    const saved = { columns: { order: ['code', 'name', 'note'], visible: [] } }
    const { result } = setup(REG_NO_LOCKED, { saved })
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['code'])
  })

  it('a betöltési fail-safe NEM ír vissza a szerverre', async () => {
    // A hookban minden PUT felhasználói művelethez kötött; egy néma, mountkori
    // mentés a felhasználó tudta nélkül módosítaná a mentett sorát.
    const saved = { columns: { order: ['code', 'name', 'note'], visible: [] } }
    setup(REG_NO_LOCKED, { saved })

    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(listPreferencesApi.update).not.toHaveBeenCalled()
  })

  it('locked oszlopot tartalmazó listán a guard nem változtat a viselkedésen', () => {
    // A jelenlegi 14 registry mindegyike ilyen: a locked oszlop már önmagában
    // megtartja a minimumot, a kapcsolható oszlopok mind kikapcsolhatók.
    const { result } = setup(REG_FRONT_ONLY)
    act(() => result.current.toggle('partner'))
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['number'])
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

describe('isDirty (a ColumnPicker "Alapértelmezett visszaállítása" gombját vezérli)', () => {
  it('érintetlen listán false — a reset gomb inaktív marad', () => {
    const { result } = setup(REG_STANDARD)
    expect(result.current.isDirty).toBe(false)
  })

  it('láthatóság-váltás után true, ugyanannak a visszakapcsolása után újra false', () => {
    const { result } = setup(REG_STANDARD)
    act(() => result.current.toggle('status'))
    expect(result.current.isDirty).toBe(true)

    act(() => result.current.toggle('status'))
    expect(result.current.isDirty).toBe(false)
  })

  it('sorrend-változás után true, akkor is, ha a láthatóság érintetlen', () => {
    // A két ág (visibilityDirty / orderDirty) külön is meg tudja billenteni az
    // isDirty-t — itt csak a sorrend változik, a látható halmaz nem.
    const { result } = setup(REG_STANDARD)
    act(() => result.current.reorder('email', 'groups'))

    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'groups', 'email', 'status', 'actions'])
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['name', 'groups', 'email', 'actions'])
    expect(result.current.isDirty).toBe(true)
  })

  it('a defaulttól eltérő MENTETT sorrenddel már mountkor true (nem kell hozzá helyi művelet)', () => {
    const saved = {
      columns: {
        order: ['name', 'groups', 'email', 'status', 'actions'],
        visible: ['name', 'email', 'groups', 'actions'], // = a registry defaultja
      },
    }
    const { result } = setup(REG_STANDARD, { saved })
    expect(result.current.isDirty).toBe(true)
  })

  it('reset után false', async () => {
    const saved = {
      columns: { order: ['name', 'groups', 'email', 'status', 'actions'], visible: ['name', 'email'] },
    }
    const { result } = setup(REG_STANDARD, { saved })
    expect(result.current.isDirty).toBe(true)

    await act(async () => { await result.current.reset() })
    expect(result.current.isDirty).toBe(false)
  })

  it('a lapméret-váltás NEM teszi dirty-vé — az isDirty csak a láthatóságot és a sorrendet nézi', () => {
    // Szándékos: a "Alapértelmezett visszaállítása" gomb az oszlopválasztó
    // panelen ül és az oszlopokra vonatkozik, ezért egy tisztán lapméret-
    // váltás után inaktív marad — noha a mentett sorban ilyenkor már van
    // eltérés a lista defaultjától.
    const { result } = setup(REG_STANDARD, { options: { defaultPageSize: 20 } })
    act(() => result.current.setPageSize(100))

    expect(result.current.pageSize).toBe(100)
    expect(result.current.isDirty).toBe(false)
  })
})

describe('mentési hibaút', () => {
  it('a PUT elutasításakor hibát jelez, de a helyi állapotot NEM görgeti vissza', async () => {
    listPreferencesApi.update.mockRejectedValue(new Error('network'))
    const { result, toast } = setup(REG_STANDARD)

    act(() => result.current.toggle('status'))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

    expect(toast).toHaveBeenCalledWith('columns.save_error', 'error')
    // A választás a képernyőn érvényben marad — csak a háttérmentés esett ki.
    expect(result.current.isVisible('status')).toBe(true)
  })

  it('sikeres mentéskor nincs hibajelzés', async () => {
    const { result, toast } = setup(REG_STANDARD)

    act(() => result.current.toggle('status'))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

    expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
    expect(toast).not.toHaveBeenCalled()
  })

  it('a reset DELETE-jének elutasításakor is jelez, a helyi visszaállítás viszont megmarad', async () => {
    listPreferencesApi.remove.mockRejectedValue(new Error('network'))
    const saved = {
      columns: { order: ['actions', 'status', 'groups', 'email', 'name'], visible: ['email'] },
    }
    const { result, toast } = setup(REG_STANDARD, { saved })

    await act(async () => { await result.current.reset() })

    expect(toast).toHaveBeenCalledWith('columns.save_error', 'error')
    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'status', 'actions'])
    expect(result.current.isDirty).toBe(false)
  })
})

describe('perzisztencia lecsatolás után (optimista context-írás)', () => {
  // Ez a blokk a bejelentett hibát fogja: a mentett preferencia egyetlen olvasási
  // forrása az /api/me pillanatképe (AuthContext.listPreferences), amit a sikeres
  // PUT nem frissít. A listáról elnavigálás LECSATOLJA a lapot, tehát visszatéréskor
  // a hook újra a pillanatképből épül — ha az nem tud a mentésről, a felhasználó
  // választása helyett a kód szerinti defaultok jönnek vissza.
  it('a lista elhagyása és visszatérése után a kikapcsolt oszlopok kikapcsolva maradnak', async () => {
    const store = createLiveAuth() // induláskor NINCS mentett sor — ez a bejelentett eset
    const first = setup(REG_STANDARD, { live: true })
    expect(first.result.current.visibleColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'actions'])

    act(() => first.result.current.toggle('email'))
    act(() => first.result.current.toggle('groups'))
    // sentinel: a context MÁR a szűkített halmazt tükrözi, még a PUT kiküldése előtt
    expect(store.listPreferences[LIST_KEY].columns.visible).toEqual(['name', 'actions'])

    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
    expect(store.listPreferences[LIST_KEY].columns.visible).toEqual(['name', 'actions'])

    // navigálás el (Partnerek) és vissza: a listaoldal újracsatolódik
    first.unmount()
    const second = setup(REG_STANDARD, { live: true })

    expect(second.result.current.visibleColumns.map((c) => c.key)).toEqual(['name', 'actions'])
    expect(second.result.current.isVisible('email')).toBe(false)
    expect(second.result.current.isVisible('groups')).toBe(false)
  })

  it('a húzással beállított sorrend is túléli az újracsatolást', async () => {
    createLiveAuth()
    const first = setup(REG_STANDARD, { live: true })
    act(() => first.result.current.reorder('email', 'groups'))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    first.unmount()

    const second = setup(REG_STANDARD, { live: true })
    expect(second.result.current.allColumns.map((c) => c.key)).toEqual(['name', 'groups', 'email', 'status', 'actions'])
  })

  it('a lapméret is túléli az újracsatolást', async () => {
    createLiveAuth()
    const PAGED = { defaultPageSize: 20 }
    const first = setup(REG_STANDARD, { live: true, options: PAGED })
    act(() => first.result.current.setPageSize(100))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    first.unmount()

    const second = setup(REG_STANDARD, { live: true, options: PAGED })
    expect(second.result.current.pageSize).toBe(100)
  })

  it('a reset a contextből is törli a sort, így az újracsatolás a defaultokat kapja', async () => {
    const store = createLiveAuth()
    const first = setup(REG_STANDARD, { live: true })
    act(() => first.result.current.toggle('email'))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(store.listPreferences).toHaveProperty(LIST_KEY)

    await act(async () => { await first.result.current.reset() })
    expect(store.listPreferences).not.toHaveProperty(LIST_KEY)
    first.unmount()

    const second = setup(REG_STANDARD, { live: true })
    expect(second.result.current.visibleColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'actions'])
  })

  it('a reset után a lecsatolás nem küld ki a törölt sorba visszaíró PUT-ot', async () => {
    // A reset a függő mentést eldobja (nem flusheli): egy utána befutó PUT épp az
    // imént visszaállított defaultot írná felül a reset ELŐTTI állapottal.
    createLiveAuth()
    const { result, unmount } = setup(REG_STANDARD, { live: true })
    act(() => result.current.toggle('email')) // a debounce-ablakon BELÜL marad
    await act(async () => { await result.current.reset() })
    unmount()
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

    expect(listPreferencesApi.update).not.toHaveBeenCalled()
    expect(listPreferencesApi.remove).toHaveBeenCalledWith(LIST_KEY)
  })

  it('a saját optimista írás NEM inicializálja újra a hookot: URL-beli lapméret mellett is a választott érték marad', async () => {
    // Regresszió-őr az optimista írás mellékhatására: a context-frissítés
    // megváltoztatja a `saved` referenciát, és ha ettől lefutna az
    // újrainicializáló effekt, a feloldás (URL > mentett > default) visszaütné a
    // felhasználó épp választott lapméretét az URL-ből jövő értékre.
    createLiveAuth()
    const { result } = setup(REG_STANDARD, {
      live: true,
      options: { defaultPageSize: 20, urlPageSize: 500 },
    })
    expect(result.current.pageSize).toBe(500)

    act(() => result.current.setPageSize(100))
    expect(result.current.pageSize).toBe(100)

    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(result.current.pageSize).toBe(100)
    expect(listPreferencesApi.update.mock.calls[0][1].page_size).toBe(100)
  })

  it('az optimista írás nem rendezi át a helyi `visible` halmazt (a toggle-sorrend marad)', async () => {
    // Ugyanannak a mellékhatásnak a másik fele. KÉT toggle kell hozzá: az első
    // utáni (kihagyott) újrainicializálás a `computeVisibleKeys`-szel registry-
    // sorrendbe normalizálná a halmazt, amit már a MÁSODIK toggle payloadja
    // elárulna — egy toggle önmagában nem mutatná meg, mert a payload a
    // beütemezéskor rögzített tömbből épül.
    createLiveAuth()
    const { result } = setup(REG_STANDARD, { live: true })
    act(() => result.current.toggle('status'))
    act(() => result.current.toggle('email'))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

    const [, payload] = listPreferencesApi.update.mock.calls[0]
    expect(payload.columns.visible).toEqual(['name', 'groups', 'actions', 'status'])
  })

  it('egymást követő módosítások a legutolsó állapotot hagyják a contextben', async () => {
    const store = createLiveAuth()
    const { result } = setup(REG_STANDARD, { live: true, options: { defaultPageSize: 20 } })

    act(() => result.current.toggle('status'))
    act(() => result.current.reorder('email', 'groups'))
    act(() => result.current.setPageSize(50))
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

    const stored = store.listPreferences[LIST_KEY]
    expect(stored.columns.visible).toContain('status')
    expect(stored.columns.order).toEqual(['name', 'groups', 'email', 'status', 'actions'])
    expect(stored.page_size).toBe(50)
    // a context és a ténylegesen kiküldött payload nem csúszhat szét
    expect(listPreferencesApi.update.mock.calls[0][1]).toEqual(stored)
  })
})

describe('cégváltás — oszlop-izoláció', () => {
  it('a másik cég mentett oszlop-preferenciája lép életbe (azonos listakulcs, cégenként külön sor)', () => {
    const companyA = {
      columns: { order: ['name', 'groups', 'email', 'status', 'actions'], visible: ['name', 'groups', 'actions'] },
    }
    const companyB = {
      columns: { order: ['name', 'email', 'groups', 'status', 'actions'], visible: ['name', 'email', 'status', 'actions'] },
    }

    const { result, rerender } = setup(REG_STANDARD, { saved: companyA, companyId: 1 })
    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'groups', 'email', 'status', 'actions'])
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['name', 'groups', 'actions'])

    mockAuth({ saved: companyB, companyId: 2 })
    rerender()

    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'email', 'groups', 'status', 'actions'])
    expect(result.current.visibleColumns.map((c) => c.key)).toEqual(['name', 'email', 'status', 'actions'])
  })

  it('cégváltáskor megvont jogosultság esetén az oszlop kiesik, akkor is, ha a mentett sor láthatóra állította', () => {
    // A jogosultsághoz kötött oszlop nem szivároghat át a másik cégbe a mentett
    // preferencián keresztül: a szűrés a `can()`-en dől el, nem a mentett soron.
    const saved = {
      columns: { order: ['name', 'email', 'secret', 'actions'], visible: ['name', 'email', 'secret', 'actions'] },
    }
    const { result, rerender } = setup(REG_WITH_PERMISSION, { saved, can: () => true, companyId: 1 })
    expect(result.current.visibleColumns.map((c) => c.key)).toContain('secret')

    mockAuth({ saved, can: (key) => key !== 'secret.view', companyId: 2 })
    rerender()

    expect(result.current.allColumns.map((c) => c.key)).toEqual(['name', 'email', 'actions'])
    expect(result.current.visibleColumns.map((c) => c.key)).not.toContain('secret')
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

    it('az ablakon belüli lecsatolás KIKÜLDI a még ki nem ment mentést (flush)', async () => {
      // Szándékos viselkedésváltozás: korábban a takarító-effekt `clearTimeout`-tal
      // ELDOBTA a függő írást, ezért a listát 600 ms-on belül elhagyó felhasználó
      // beállítása sosem jutott el a szerverig (a képernyőn megmaradt, egy
      // újratöltés után viszont eltűnt). Most a lecsatolás azonnali, fire-and-forget
      // PUT-tal üríti a függő mentést.
      const { result, unmount } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(100))
      expect(listPreferencesApi.update).not.toHaveBeenCalled()

      unmount()

      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
      expect(listPreferencesApi.update.mock.calls[0][1].page_size).toBe(100)

      // a lecsatolt komponens időzítője nem éledhet újra: nincs második PUT
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
    })

    it('a flush a legutolsó állapotot küldi, oszlopokkal együtt', async () => {
      const { result, unmount } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.toggle('status'))
      await act(async () => { await vi.advanceTimersByTimeAsync(300) }) // az ablakon BELÜL
      act(() => result.current.setPageSize(200))
      unmount()

      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
      const [key, payload] = listPreferencesApi.update.mock.calls[0]
      expect(key).toBe(LIST_KEY)
      expect(payload.page_size).toBe(200)
      expect(payload.columns.visible).toContain('status')
    })

    it('a MÁR elsült debounce után a lecsatolás nem küld duplán', async () => {
      const { result, unmount } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(100))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)

      unmount()

      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
    })

    it('művelet nélküli lecsatolás egyáltalán nem küld PUT-ot', async () => {
      const { unmount } = setup(REG_STANDARD, { options: PAGED })
      unmount()

      expect(listPreferencesApi.update).not.toHaveBeenCalled()
    })

    it('cégváltás után a lecsatolás sem küldi ki az eldobott mentést', async () => {
      // A cégváltáskor eldobott írás nem éledhet újra a flushon keresztül — a
      // szerveren már a MÁSIK cég sorába íródna (a cég-kontextust a session adja).
      const { result, rerender, unmount } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(100))

      mockAuth({ companyId: 2 })
      rerender()
      unmount()
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      expect(listPreferencesApi.update).not.toHaveBeenCalled()
    })

    it('a flush hibája is jelzést ad (a mentés a lap elhagyása után sem néma)', async () => {
      listPreferencesApi.update.mockRejectedValue(new Error('network'))
      const { result, unmount, toast } = setup(REG_STANDARD, { options: PAGED })
      act(() => result.current.setPageSize(100))

      unmount()
      await act(async () => { await vi.advanceTimersByTimeAsync(0) }) // a fire-and-forget promise lefutása

      expect(toast).toHaveBeenCalledWith('columns.save_error', 'error')
    })
  })
})

describe('rendezés (sort)', () => {
  describe('betöltés a mentett preferenciából', () => {
    it('mentett sort nélkül nincs saját rendezés (a végpont alapértelmezése érvényes)', () => {
      const { result } = setup(REG_SORTABLE)
      expect(result.current.sort).toBe(null)
    })

    it('az érvényes mentett {by, dir} visszaáll', () => {
      const { result } = setup(REG_SORTABLE, { saved: { sort: { by: 'partner', dir: 'desc' } } })
      expect(result.current.sort).toEqual({ by: 'partner', dir: 'desc' })
    })

    it('ismeretlen oszlopkulcsra mutató mentés nem lép életbe', () => {
      // A backend a kulcs LÉTEZÉSÉT nem validálja (l. UpdateListPreferenceRequest),
      // ezért egy azóta megszűnt oszlopra mutató mentés a frontendre marad.
      const { result } = setup(REG_SORTABLE, { saved: { sort: { by: 'megszunt_oszlop', dir: 'asc' } } })
      expect(result.current.sort).toBe(null)
    })

    it('nem rendezhetőnek jelölt oszlopra mutató mentés nem lép életbe', () => {
      const { result } = setup(REG_SORTABLE, { saved: { sort: { by: 'note', dir: 'asc' } } })
      expect(result.current.sort).toBe(null)
    })

    it('érvénytelen irányú mentés nem lép életbe', () => {
      const { result } = setup(REG_SORTABLE, { saved: { sort: { by: 'partner', dir: 'oldalra' } } })
      expect(result.current.sort).toBe(null)
    })

    it('idegen alakú (nem {by, dir}) mentés nem lép életbe', () => {
      const { result } = setup(REG_SORTABLE, { saved: { sort: '-created_at' } })
      expect(result.current.sort).toBe(null)
    })

    it('a jog nélküli oszlopra mutató mentés nem lép életbe', () => {
      const registry = [
        { key: 'name', default: true, locked: true, sortable: true },
        { key: 'secret', default: true, permission: 'secret.view', sortable: true },
      ]
      const { result } = setup(registry, {
        saved: { sort: { by: 'secret', dir: 'asc' } },
        can: (perm) => perm !== 'secret.view',
      })
      expect(result.current.sort).toBe(null)
    })
  })

  describe('setSort', () => {
    it('beállítja a rendezést és mentést indít', async () => {
      const { result } = setup(REG_SORTABLE)
      act(() => result.current.setSort('partner', 'desc'))

      expect(result.current.sort).toEqual({ by: 'partner', dir: 'desc' })

      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
      const [, payload] = listPreferencesApi.update.mock.calls[0]
      expect(payload.sort).toEqual({ by: 'partner', dir: 'desc' })
    })

    it('a rendezés az oszlopokkal EGY payloadban megy ki (nem külön PUT)', async () => {
      const { result } = setup(REG_SORTABLE)
      act(() => result.current.setSort('partner', 'asc'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      expect(listPreferencesApi.update).toHaveBeenCalledTimes(1)
      const [, payload] = listPreferencesApi.update.mock.calls[0]
      expect(payload.columns.visible).toEqual(['number', 'partner', 'issue_date'])
      expect(payload.sort).toEqual({ by: 'partner', dir: 'asc' })
    })

    it('ismeretlen vagy nem rendezhető oszlopra nem áll át, és nem is ment', async () => {
      const { result } = setup(REG_SORTABLE)
      act(() => result.current.setSort('note', 'asc'))
      act(() => result.current.setSort('nincs_ilyen', 'asc'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      expect(result.current.sort).toBe(null)
      expect(listPreferencesApi.update).not.toHaveBeenCalled()
    })

    it('érvénytelen irány növekvőre normalizálódik', () => {
      const { result } = setup(REG_SORTABLE)
      act(() => result.current.setSort('partner', 'oldalra'))
      expect(result.current.sort).toEqual({ by: 'partner', dir: 'asc' })
    })

    it('setSort(null) törli a rendezést, és a payloadból is kimarad a mező', async () => {
      const { result } = setup(REG_SORTABLE, { saved: { sort: { by: 'partner', dir: 'asc' } } })
      act(() => result.current.setSort(null))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      expect(result.current.sort).toBe(null)
      const [, payload] = listPreferencesApi.update.mock.calls[0]
      expect(payload).not.toHaveProperty('sort')
    })

    it('a rendezés nem írja felül a mentett oszlop-sorrendet és lapméretet', async () => {
      const { result } = setup(REG_SORTABLE, {
        saved: {
          columns: { order: ['number', 'issue_date', 'partner', 'note'], visible: ['number', 'issue_date'] },
          page_size: 50,
        },
        options: { defaultPageSize: 20 },
      })
      act(() => result.current.setSort('partner', 'asc'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      const [, payload] = listPreferencesApi.update.mock.calls[0]
      expect(payload.page_size).toBe(50)
      expect(payload.columns.order).toEqual(['number', 'issue_date', 'partner', 'note'])
      expect(payload.columns.visible).toEqual(['number', 'issue_date'])
    })
  })

  describe('toggleSort — háromállapotú ciklus', () => {
    it('szöveges oszlop: növekvő → csökkenő → alapértelmezett', () => {
      const { result } = setup(REG_SORTABLE)

      act(() => result.current.toggleSort('partner'))
      expect(result.current.sort).toEqual({ by: 'partner', dir: 'asc' })

      act(() => result.current.toggleSort('partner'))
      expect(result.current.sort).toEqual({ by: 'partner', dir: 'desc' })

      act(() => result.current.toggleSort('partner'))
      expect(result.current.sort).toBe(null)
    })

    it('sortInitialDir: "desc" oszlop csökkenővel indul, majd növekvő, majd alapértelmezett', () => {
      const { result } = setup(REG_SORTABLE)

      act(() => result.current.toggleSort('issue_date'))
      expect(result.current.sort).toEqual({ by: 'issue_date', dir: 'desc' })

      act(() => result.current.toggleSort('issue_date'))
      expect(result.current.sort).toEqual({ by: 'issue_date', dir: 'asc' })

      act(() => result.current.toggleSort('issue_date'))
      expect(result.current.sort).toBe(null)
    })

    it('MÁSIK oszlopra váltva a ciklus elölről indul, nem folytatódik', () => {
      const { result } = setup(REG_SORTABLE)

      act(() => result.current.toggleSort('partner'))
      act(() => result.current.toggleSort('partner')) // partner/desc
      act(() => result.current.toggleSort('issue_date'))

      expect(result.current.sort).toEqual({ by: 'issue_date', dir: 'desc' })
    })

    it('locked oszlop is rendezhető, ha sortable (a rejthetőség és a rendezés független)', () => {
      const { result } = setup(REG_SORTABLE)
      act(() => result.current.toggleSort('number'))
      expect(result.current.sort).toEqual({ by: 'number', dir: 'asc' })
    })

    it('nem rendezhető oszlopon a kattintás no-op', () => {
      const { result } = setup(REG_SORTABLE)
      act(() => result.current.toggleSort('note'))
      expect(result.current.sort).toBe(null)
    })

    it('két, egy tickben leadott kattintás is helyesen lépteti a ciklust', () => {
      // A ciklus a `sortRef`-ből számol (nem a renderelt state-ből), ezért egy
      // gyors dupla kattintás sem "ragadhat be" az első lépésnél.
      const { result } = setup(REG_SORTABLE)
      act(() => {
        result.current.toggleSort('partner')
        result.current.toggleSort('partner')
      })
      expect(result.current.sort).toEqual({ by: 'partner', dir: 'desc' })
    })
  })

  describe('kölcsönhatás a többi funkcióval', () => {
    it('rendezés-módosítás után a visszaállító gomb aktív (isDirty)', () => {
      const { result } = setup(REG_SORTABLE)
      expect(result.current.isDirty).toBe(false)

      act(() => result.current.toggleSort('partner'))
      expect(result.current.isDirty).toBe(true)
    })

    it('oszlop-kapcsolás megőrzi a mentett rendezést', async () => {
      const { result } = setup(REG_SORTABLE, { saved: { sort: { by: 'partner', dir: 'desc' } } })
      act(() => result.current.toggle('note'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      const [, payload] = listPreferencesApi.update.mock.calls[0]
      expect(payload.sort).toEqual({ by: 'partner', dir: 'desc' })
    })

    it('a rendezés túléli a lecsatolást (optimista context-írás)', async () => {
      createLiveAuth()
      const first = setup(REG_SORTABLE, { live: true })
      act(() => first.result.current.toggleSort('partner'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
      first.unmount()

      const second = setup(REG_SORTABLE, { live: true })
      expect(second.result.current.sort).toEqual({ by: 'partner', dir: 'asc' })
    })

    it('a reset a rendezést is visszaállítja az alapértelmezettre', async () => {
      const { result } = setup(REG_SORTABLE, { saved: { sort: { by: 'partner', dir: 'desc' } } })
      expect(result.current.sort).toEqual({ by: 'partner', dir: 'desc' })

      await act(async () => { await result.current.reset() })

      expect(result.current.sort).toBe(null)
      expect(listPreferencesApi.remove).toHaveBeenCalledWith(LIST_KEY)
    })

    it('cégváltáskor a másik cég rendezése lép életbe', () => {
      const { result, rerender } = setup(REG_SORTABLE, { saved: { sort: { by: 'partner', dir: 'asc' } } })
      expect(result.current.sort).toEqual({ by: 'partner', dir: 'asc' })

      mockAuth({ saved: { sort: { by: 'issue_date', dir: 'desc' } }, companyId: 2 })
      rerender()

      expect(result.current.sort).toEqual({ by: 'issue_date', dir: 'desc' })
    })

    it('rendezés-UI NÉLKÜLI listán a mentett sort mező érintetlenül megmarad', async () => {
      // A 14 listából 13 ilyen: nincs `sortable` oszlopa, ezért a hook a mentett
      // értéket nem értelmezi, csak visszaküldi — ez az eddigi viselkedés.
      const { result } = setup(REG_STANDARD, { saved: { sort: { by: 'barmi', dir: 'asc' } } })
      expect(result.current.sort).toBe(null)

      act(() => result.current.toggle('status'))
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

      const [, payload] = listPreferencesApi.update.mock.calls[0]
      expect(payload.sort).toEqual({ by: 'barmi', dir: 'asc' })
    })
  })
})
