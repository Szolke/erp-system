import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RequirePermission from './RequirePermission'
import { useAuth } from '../contexts/AuthContext'
import { useTranslation } from '../contexts/TranslationContext'

vi.mock('../contexts/AuthContext', () => ({ useAuth: vi.fn() }))
vi.mock('../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))

function t(key) {
  return key
}

beforeEach(() => {
  useTranslation.mockReturnValue({ t })
})

// A guard `useParams()`-t hív, ezért valódi router-környezetben renderelünk
// (MemoryRouter + Route), nem mockoljuk a react-router-dom-ot.
function renderGuard({ access, path = '/target', initialPath = '/target' } = {}) {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route
          path={path}
          element={
            <RequirePermission access={access}>
              <div>Védett tartalom</div>
            </RequirePermission>
          }
        />
      </Routes>
    </MemoryRouter>
  )
}

describe('RequirePermission — perm-alapú védelem', () => {
  it('meglévő joggal a children renderelődik', () => {
    useAuth.mockReturnValue({ user: { id: 1 }, can: (p) => p === 'partner.view' })
    renderGuard({ access: { perm: 'partner.view' } })

    expect(screen.getByText('Védett tartalom')).toBeInTheDocument()
  })

  it('hiányzó joggal a tiltó nézet jelenik meg, a children NEM renderelődik', () => {
    useAuth.mockReturnValue({ user: { id: 1 }, can: () => false })
    renderGuard({ access: { perm: 'partner.view' } })

    expect(screen.queryByText('Védett tartalom')).not.toBeInTheDocument()
    expect(screen.getByText('common.no_page_permission')).toBeInTheDocument()
  })
})

describe('RequirePermission — anyPerm', () => {
  it('a felsoroltak közül egy jog is elég', () => {
    useAuth.mockReturnValue({ user: { id: 1 }, can: (p) => p === 'receipt.view' })
    renderGuard({ access: { anyPerm: ['invoice.view', 'receipt.view'] } })

    expect(screen.getByText('Védett tartalom')).toBeInTheDocument()
  })

  it('egyik felsorolt jog sincs meg → tiltás', () => {
    useAuth.mockReturnValue({ user: { id: 1 }, can: () => false })
    renderGuard({ access: { anyPerm: ['invoice.view', 'receipt.view'] } })

    expect(screen.queryByText('Védett tartalom')).not.toBeInTheDocument()
  })
})

describe('RequirePermission — superadmin-only route', () => {
  it('superadminnak megy', () => {
    useAuth.mockReturnValue({ user: { id: 1, is_superadmin: true }, can: () => false })
    renderGuard({ access: { superadminOnly: true } })

    expect(screen.getByText('Védett tartalom')).toBeInTheDocument()
  })

  it('nem-superadminnak nem megy, még ha minden can() true is lenne', () => {
    useAuth.mockReturnValue({ user: { id: 1, is_superadmin: false }, can: () => true })
    renderGuard({ access: { superadminOnly: true } })

    expect(screen.queryByText('Védett tartalom')).not.toBeInTheDocument()
  })
})

describe('RequirePermission — public route', () => {
  it('nincs feltétel, mindig renderel', () => {
    useAuth.mockReturnValue({ user: null, can: () => false })
    renderGuard({ access: { public: true } })

    expect(screen.getByText('Védett tartalom')).toBeInTheDocument()
  })
})

describe('RequirePermission — betöltetlen jogosultság-állapot (üres permissions) — fail-closed', () => {
  it('user és can() is "üres" állapotot tükröz → tiltás, NEM fail-open', () => {
    useAuth.mockReturnValue({ user: null, can: () => false })
    renderGuard({ access: { perm: 'partner.view' } })

    expect(screen.queryByText('Védett tartalom')).not.toBeInTheDocument()
    expect(screen.getByText('common.no_page_permission')).toBeInTheDocument()
  })

  it('betöltetlen állapotban a superadminOnly is tiltást ad (user undefined)', () => {
    useAuth.mockReturnValue({ user: undefined, can: () => false })
    renderGuard({ access: { superadminOnly: true } })

    expect(screen.queryByText('Védett tartalom')).not.toBeInTheDocument()
  })
})

describe('RequirePermission — /users/:id egyedi szabály (saját profil user.view nélkül is)', () => {
  const access = {
    check: ({ user, can, params }) => can('user.view') || Number(params?.id) === user?.id,
  }

  it('user.view joggal bárkinek megy', () => {
    useAuth.mockReturnValue({ user: { id: 1 }, can: (p) => p === 'user.view' })
    renderGuard({ access, path: '/users/:id', initialPath: '/users/42' })

    expect(screen.getByText('Védett tartalom')).toBeInTheDocument()
  })

  it('user.view nélkül, de a SAJÁT profiljára megy a felhasználó', () => {
    useAuth.mockReturnValue({ user: { id: 42 }, can: () => false })
    renderGuard({ access, path: '/users/:id', initialPath: '/users/42' })

    expect(screen.getByText('Védett tartalom')).toBeInTheDocument()
  })

  it('user.view nélkül, MÁS felhasználó profiljára tiltás', () => {
    useAuth.mockReturnValue({ user: { id: 1 }, can: () => false })
    renderGuard({ access, path: '/users/:id', initialPath: '/users/42' })

    expect(screen.queryByText('Védett tartalom')).not.toBeInTheDocument()
  })
})
