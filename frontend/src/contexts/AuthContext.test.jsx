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

function meResponse({ permissions = [], isSuperadmin = false, companyId = 1 } = {}) {
  return {
    data: {
      user: { id: 1, is_superadmin: isSuperadmin, locale: 'hu' },
      companies: [{ id: companyId, name: 'Test Co' }],
      active_company_id: companyId,
      permissions,
      list_preferences: {},
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

  it('MEGFIGYELÉS (nem hiba, dokumentálva): switchCompany() az activeCompanyId-t a fetchMe() ELŐTT állítja át — az API-hívás és a permissions-frissülés között az UI rövid ideig az ÚJ cég azonosítóját mutatja a RÉGI cég jogaival párosítva', async () => {
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

    // az apiSwitch(2) hívás még függőben van -> activeCompanyId még a RÉGI (1)
    expect(result.current.activeCompanyId).toBe(1)

    await act(async () => {
      resolveSwitch({})
      await Promise.resolve() // engedjük lefutni a setActiveCompanyId-t + a fetchMe() indítását
    })

    // apiSwitch lezárult -> activeCompanyId már 2, DE a permissions még a RÉGI (1-es) cégé,
    // mert a fetchMe()-n belüli `await me()` még nem oldódott fel.
    expect(result.current.activeCompanyId).toBe(2)
    expect(result.current.can('invoice.view')).toBe(true) // a RÉGI cég joga még "aktív"
    expect(result.current.can('partner.edit')).toBe(false) // az ÚJ cég joga még nem érkezett meg

    await act(async () => {
      resolveMe(meResponse({ permissions: ['partner.edit'], companyId: 2 }))
    })

    // a válasz megérkezett -> a permissions immár az ÚJ cégé
    expect(result.current.can('invoice.view')).toBe(false)
    expect(result.current.can('partner.edit')).toBe(true)
  })
})
