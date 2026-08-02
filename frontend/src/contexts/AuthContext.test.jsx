import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider, useAuth } from './AuthContext'
import { logout, me, switchCompany } from '../api/auth'
import { useTranslation } from './TranslationContext'

// `can()` egyáltalán nem exportált önállóan — kizárólag az AuthProvider
// belsejében élő `permissions` state-re záródó closure (l. AuthContext.jsx).
// Ezért ezt a kört, a feladat instrukciója szerint, a Context szintjén
// teszteljük: valódi AuthProvider + renderHook(() => useAuth(), { wrapper }),
// az AuthProvider egyetlen két függősége (`../api/auth`, `./TranslationContext`)
// mockolva — a useListColumns.test.js-ben bevált vi.mock-mintát követve.
vi.mock('../api/auth', () => ({
  me: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchCompany: vi.fn(),
}))
vi.mock('./TranslationContext', () => ({ useTranslation: vi.fn() }))

function meResponse({ permissions = [], isSuperadmin = false, companyId = 1, listPreferences = {} } = {}) {
  return {
    data: {
      user: { id: 1, is_superadmin: isSuperadmin, locale: 'hu' },
      companies: [{ id: companyId, name: 'Test Co' }],
      active_company_id: companyId,
      permissions,
      list_preferences: listPreferences,
    },
  }
}

beforeEach(() => {
  useTranslation.mockReturnValue({
    setLocale: vi.fn().mockResolvedValue(undefined),
    loadLocale: vi.fn().mockResolvedValue(undefined),
  })
})

afterEach(() => {
  vi.clearAllMocks()
})

function setup() {
  return renderHook(() => useAuth(), { wrapper: AuthProvider })
}

describe('can() — meglévő/hiányzó/elgépelt kulcs', () => {
  it('a permissions tömbben szereplő kulcsra true-t ad', async () => {
    me.mockResolvedValue(meResponse({ permissions: ['invoice.view', 'partner.edit'] }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.can('invoice.view')).toBe(true)
    expect(result.current.can('partner.edit')).toBe(true)
  })

  it('a permissions tömbből hiányzó vagy elgépelt kulcsra false-t ad (nincs wildcard/prefix logika)', async () => {
    me.mockResolvedValue(meResponse({ permissions: ['invoice.view'] }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.can('invoice.delete')).toBe(false) // egyszerűen hiányzik
    expect(result.current.can('invoice.viewx')).toBe(false) // elgépelt — nincs "kezdődik-e vele" egyezés
    expect(result.current.can('invoice')).toBe(false) // maga a prefix NEM elég — exact match kell
    expect(result.current.can('invoice.view.extra')).toBe(false) // superstring sem elég
  })
})

describe('superadmin — a can()-ban nincs külön ág', () => {
  it('a can() superadminnél is sima .includes(): a szerver már kibontott, teljes kulcslistát küld', async () => {
    // A backend PermissionChecker superadminnél a jelenleg engedélyezett modulokhoz
    // tartozó ÖSSZES permission-kulcsot előre kibontva küldi (nem pl. egy '*' jelölőt) —
    // a frontend can()-ja emiatt szerkezetében ugyanaz marad, mint bármely más usernél.
    me.mockResolvedValue(meResponse({ permissions: ['invoice.view', 'user.manage'], isSuperadmin: true }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.user.is_superadmin).toBe(true)
    expect(result.current.can('user.manage')).toBe(true)
    // ha egy kulcs (pl. kikapcsolt modul miatt) HIÁNYZIK a kapott listából, a can()
    // superadmin usernél is false-t ad — a frontend semmit nem told fel magától.
    expect(result.current.can('nav.view_log')).toBe(false)
  })
})

describe('betöltetlen/üres állapot — fail-closed', () => {
  it('mielőtt az /api/me válaszol, minden can() hívás false (NEM fail-open)', () => {
    me.mockReturnValue(new Promise(() => {})) // sosem oldódik fel — a betöltés-alatti pillanatot vizsgáljuk
    const { result } = setup()

    expect(result.current.loading).toBe(true)
    expect(result.current.permissions).toEqual([])
    expect(result.current.can('invoice.view')).toBe(false)
    expect(result.current.can('anything.at.all')).toBe(false)
  })

  it('sikertelen /api/me (pl. nincs bejelentkezve, 401) után is üres permissions, can() mindenre false', async () => {
    me.mockRejectedValue(new Error('401'))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.user).toBe(null)
    expect(result.current.permissions).toEqual([])
    expect(result.current.can('invoice.view')).toBe(false)
  })

  it('logout azonnal (szinkron state-frissítéssel) üríti a permissions-t, can() rögtön false-ra vált', async () => {
    me.mockResolvedValue(meResponse({ permissions: ['invoice.view'] }))
    logout.mockResolvedValue({})
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))
    expect(result.current.can('invoice.view')).toBe(true)

    await act(async () => { await result.current.logout() })

    expect(result.current.can('invoice.view')).toBe(false)
    expect(result.current.user).toBe(null)
  })
})

describe('lista-preferenciák — optimista frissítés (mergeListPreference / clearListPreference)', () => {
  // A `listPreferences` egyébként csak az /api/me válaszából frissülne, ezért egy
  // sikeres oszlop-mentés után a context állott maradna (l. useListColumns).
  const DOCS = { columns: { visible: ['number'], order: ['number', 'partner'] } }
  const PARTNERS = { columns: { visible: ['name', 'email'], order: ['name', 'email'] }, page_size: 50 }

  it('a merge felveszi az új kulcsot, a meglévőket érintetlenül hagyva, ÚJ objektumban', async () => {
    me.mockResolvedValue(meResponse({ listPreferences: { 'partners.index': PARTNERS } }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))
    const before = result.current.listPreferences

    act(() => result.current.mergeListPreference('documents.index', DOCS))

    expect(result.current.listPreferences).toEqual({ 'partners.index': PARTNERS, 'documents.index': DOCS })
    // immutábilis: a korábbi objektum nem módosult, csak új példány készült
    expect(result.current.listPreferences).not.toBe(before)
    expect(before).toEqual({ 'partners.index': PARTNERS })
  })

  it('a merge egy meglévő kulcs értékét teljesen lecseréli (nem mélyen összefésüli)', async () => {
    me.mockResolvedValue(meResponse({ listPreferences: { 'documents.index': { columns: { visible: ['number', 'partner'], order: ['number', 'partner'] }, page_size: 20 } } }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    act(() => result.current.mergeListPreference('documents.index', DOCS))

    // a régi `page_size: 20` NEM marad ott: a hook mindig teljes payloadot ad át,
    // ahogy a PUT is teljes upsert a backend oldalán
    expect(result.current.listPreferences['documents.index']).toEqual(DOCS)
  })

  it('a clear csak a megadott kulcsot törli', async () => {
    me.mockResolvedValue(meResponse({ listPreferences: { 'documents.index': DOCS, 'partners.index': PARTNERS } }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    act(() => result.current.clearListPreference('documents.index'))

    expect(result.current.listPreferences).toEqual({ 'partners.index': PARTNERS })
  })

  it('nem létező kulcs törlése ugyanazt az objektumot hagyja (nincs felesleges újrarender)', async () => {
    me.mockResolvedValue(meResponse({ listPreferences: { 'partners.index': PARTNERS } }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))
    const before = result.current.listPreferences

    act(() => result.current.clearListPreference('documents.index'))

    expect(result.current.listPreferences).toBe(before)
  })

  it('cégváltás után az ÚJ cég /api/me válasza felülírja az optimista értékeket (nincs átszivárgás)', async () => {
    // Ez a garancia teszi feleslegessé, hogy a setterek cégenként kulcsoljanak:
    // a `listPreferences` mindig az AKTUÁLIS cég sorait tartalmazza, mert a
    // switchCompany egy teljes fetchMe()-t futtat.
    me.mockResolvedValueOnce(meResponse({ companyId: 1, listPreferences: {} }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    act(() => result.current.mergeListPreference('documents.index', DOCS))
    expect(result.current.listPreferences['documents.index']).toEqual(DOCS)

    switchCompany.mockResolvedValue({})
    me.mockResolvedValueOnce(meResponse({ companyId: 2, listPreferences: { 'partners.index': PARTNERS } }))
    await act(async () => { await result.current.switchCompany(2) })

    expect(result.current.listPreferences).toEqual({ 'partners.index': PARTNERS })
  })

  it('logout üríti a lista-preferenciákat is', async () => {
    me.mockResolvedValue(meResponse({ listPreferences: { 'documents.index': DOCS } }))
    logout.mockResolvedValue({})
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    await act(async () => { await result.current.logout() })

    expect(result.current.listPreferences).toEqual({})
  })
})

describe('cégváltás — a permissions frissül-e', () => {
  it('switchCompany lezárulása után az ÚJ cég /api/me válasza szerinti permissions érvényes', async () => {
    me.mockResolvedValueOnce(meResponse({ permissions: ['invoice.view'], companyId: 1 }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))
    expect(result.current.can('invoice.view')).toBe(true)
    expect(result.current.can('partner.edit')).toBe(false)

    switchCompany.mockResolvedValue({})
    me.mockResolvedValueOnce(meResponse({ permissions: ['partner.edit'], companyId: 2 }))

    await act(async () => { await result.current.switchCompany(2) })

    expect(result.current.activeCompanyId).toBe(2)
    expect(result.current.can('partner.edit')).toBe(true)
    expect(result.current.can('invoice.view')).toBe(false)
  })

  it('JAVÍTVA (korábban MEGFIGYELÉS volt, szándékos viselkedésváltozás): a switchCompany() már NEM állítja át az activeCompanyId-t a fetchMe() előtt — az activeCompanyId és a permissions a `me()` válaszával EGYÜTT, egyetlen frissítésben vált, ezért nincs olyan köztes állapot, ahol az ÚJ cég azonosítója a RÉGI cég jogaival párosul', async () => {
    me.mockResolvedValueOnce(meResponse({ permissions: ['invoice.view'], companyId: 1 }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    let resolveSwitch
    switchCompany.mockReturnValue(new Promise((resolve) => { resolveSwitch = resolve }))
    let resolveMe
    me.mockReturnValueOnce(new Promise((resolve) => { resolveMe = resolve }))

    act(() => {
      result.current.switchCompany(2) // szándékosan nem awaitolva — a köztes állapotot vizsgáljuk
    })

    // az apiSwitch(2) hívás még függőben van -> activeCompanyId még a RÉGI (1), a RÉGI jogokkal
    expect(result.current.activeCompanyId).toBe(1)
    expect(result.current.can('invoice.view')).toBe(true)

    await act(async () => {
      resolveSwitch({})
      await Promise.resolve() // engedjük lefutni az apiSwitch()-et követő fetchMe()-indítást
    })

    // apiSwitch lezárult, DE a fetchMe()-n belüli `await me()` még nem oldódott fel — mivel az
    // AuthContext.jsx MÁR NEM állítja át korán az activeCompanyId-t, itt MÉG MINDIG a RÉGI (1)
    // az aktív cég, a RÉGI jogokkal konzisztensen párosítva. Ez a lényegi különbség a korábbi
    // (hibás) viselkedéshez képest: akkor itt már 2 lett volna activeCompanyId, RÉGI jogokkal.
    expect(result.current.activeCompanyId).toBe(1)
    expect(result.current.can('invoice.view')).toBe(true)
    expect(result.current.can('partner.edit')).toBe(false)

    await act(async () => {
      resolveMe(meResponse({ permissions: ['partner.edit'], companyId: 2 }))
    })

    // a /api/me válasza megérkezett -> activeCompanyId ÉS permissions EGYSZERRE, egyetlen
    // React-frissítésben vált az ÚJ cégére (React 19 automatikus batching a setState-sorozatra)
    expect(result.current.activeCompanyId).toBe(2)
    expect(result.current.can('partner.edit')).toBe(true)
    expect(result.current.can('invoice.view')).toBe(false)
  })

  it('a köztes ablakban a can() NEM ad true-t egy olyan kulcsra, ami csak a RÉGI cégnél volt meg, az ÚJ cég azonosítója alá párosítva — mert az activeCompanyId is a RÉGI marad, amíg a permissions is az', async () => {
    me.mockResolvedValueOnce(meResponse({ permissions: ['invoice.view'], companyId: 1 }))
    const { result } = setup()
    await waitFor(() => expect(result.current.loading).toBe(false))

    let resolveSwitch
    switchCompany.mockReturnValue(new Promise((resolve) => { resolveSwitch = resolve }))
    me.mockReturnValueOnce(new Promise(() => {})) // ebben a tesztben sosem oldódik fel

    act(() => { result.current.switchCompany(2) })
    await act(async () => {
      resolveSwitch({})
      await Promise.resolve()
    })

    // a köztes ablakban SOSEM áll elő "ÚJ cég azonosítója + RÉGI cég joga" kombináció
    const showingNewCompanyId = result.current.activeCompanyId === 2
    const hasOldCompanyOnlyPermission = result.current.can('invoice.view')
    expect(showingNewCompanyId && hasOldCompanyOnlyPermission).toBe(false)
    // konkrétan: a RÉGI cég marad érvényben, konzisztensen, amíg az új válasz meg nem érkezik
    expect(result.current.activeCompanyId).toBe(1)
  })
})
