import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import SalesGroupMembersSection from './SalesGroupMembersSection'
import { useTranslation } from '../contexts/TranslationContext'
import { useToast } from '../contexts/ToastContext'
import { salesGroups as sgApi } from '../api/salesGroups'
import { users as usersApi } from '../api/users'

vi.mock('../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))
vi.mock('../contexts/ToastContext', () => ({ useToast: vi.fn() }))
vi.mock('../api/salesGroups', () => ({
  salesGroups: { listUsers: vi.fn(), syncUsers: vi.fn() },
}))
vi.mock('../api/users', () => ({
  users: { list: vi.fn() },
}))

/** Determinisztikus t(): a kulcsot adja vissza, így a teszt arra tud állítani. */
function t(key) {
  return key
}

const toast = vi.fn()

function user(id, name, email) {
  return { id, name, email }
}

/** Axios-alakú válasz a tagság-végponthoz ({ data: [...] } burkolóval). */
function membersResponse(list) {
  return { data: { data: list } }
}

/** Axios-alakú válasz a lapozott /api/users-hez. */
function usersResponse(list) {
  return { data: { data: list } }
}

function apiError(status, data) {
  return { response: { status, data } }
}

function renderSection(props = {}) {
  return render(
    <SalesGroupMembersSection
      groupId={7}
      canEdit
      canViewUsers
      onDirtyChange={() => {}}
      {...props}
    />
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useTranslation.mockReturnValue({ t })
  useToast.mockReturnValue(toast)
  sgApi.listUsers.mockResolvedValue(membersResponse([]))
  usersApi.list.mockResolvedValue(usersResponse([]))
})

describe('SalesGroupMembersSection — jelöltek betöltése', () => {
  it('megnyitáskor üres kereséssel, 50-es oldalmérettel kéri le a jelölteket', async () => {
    usersApi.list.mockResolvedValue(usersResponse([user(1, 'Anna', 'anna@x.hu')]))

    renderSection()

    await waitFor(() => expect(usersApi.list).toHaveBeenCalledTimes(1))
    // A `search` szándékosan undefined üres keresésnél — így ki sem kerül a query stringbe.
    expect(usersApi.list).toHaveBeenCalledWith({ search: undefined, per_page: 50 })
    expect(await screen.findByText(/Anna/)).toBeInTheDocument()
  })

  it('gépelésre debounce-olva, EGYETLEN kérést küld az utolsó állapottal', async () => {
    const u = userEvent.setup()
    renderSection()
    await waitFor(() => expect(usersApi.list).toHaveBeenCalledTimes(1))

    usersApi.list.mockResolvedValue(usersResponse([user(9, 'Zoltán', 'zoltan@x.hu')]))
    await u.type(screen.getByLabelText('Keresés névre / e-mailre'), 'zol')

    // A négy leütés (z, o, l) nem indít négy kérést: a debounce összevonja őket,
    // és csak a végső "zol" megy ki.
    await waitFor(() => expect(usersApi.list).toHaveBeenCalledTimes(2))
    expect(usersApi.list).toHaveBeenLastCalledWith({ search: 'zol', per_page: 50 })
    expect(await screen.findByText(/Zoltán/)).toBeInTheDocument()
  })

  it('üres találati listára "Nincs találat" üzenetet mutat', async () => {
    renderSection()

    expect(await screen.findByText('Nincs találat.')).toBeInTheDocument()
  })

  it('a keresés hibáját megmutatja, és nem dobja el a szekciót', async () => {
    usersApi.list.mockRejectedValue(apiError(500, { message: 'Szerverhiba a keresésben.' }))

    renderSection()

    expect(await screen.findByText('Szerverhiba a keresésben.')).toBeInTheDocument()
    expect(screen.getByText('A keresés nem sikerült.')).toBeInTheDocument()
    // A mentés gomb (és vele a szekció) továbbra is a helyén van.
    expect(screen.getByRole('button', { name: 'Tagság mentése' })).toBeInTheDocument()
  })

  it('a tagság-lekérés hibáját külön, a szekció fejében jelzi', async () => {
    sgApi.listUsers.mockRejectedValue(apiError(403, { message: 'Nincs jogosultság.' }))

    renderSection()

    expect(await screen.findByText('Nincs jogosultság.')).toBeInTheDocument()
  })
})

describe('SalesGroupMembersSection — kiválasztott blokk perzisztenciája', () => {
  it('a meglévő tag akkor is látszik, ha a keresés nem hozza vissza', async () => {
    const u = userEvent.setup()
    sgApi.listUsers.mockResolvedValue(membersResponse([user(1, 'Anna', 'anna@x.hu')]))
    usersApi.list.mockResolvedValue(usersResponse([user(1, 'Anna', 'anna@x.hu')]))

    renderSection()
    await waitFor(() => expect(usersApi.list).toHaveBeenCalledTimes(1))

    // Olyan keresés, aminek Anna nem felel meg — a találatok kicserélődnek.
    usersApi.list.mockResolvedValue(usersResponse([user(9, 'Zoltán', 'zoltan@x.hu')]))
    await u.type(screen.getByLabelText('Keresés névre / e-mailre'), 'zol')
    await screen.findByText(/Zoltán/)

    // Anna a kiválasztott blokkban továbbra is ott van — nem tűnt el.
    expect(screen.getByRole('button', { name: 'Anna eltávolítása' })).toBeInTheDocument()
    expect(screen.getByText('(1 kiválasztva)')).toBeInTheDocument()
  })

  it('egy korábbi keresésből bepipált user a keresés cseréje után is kiválasztva marad', async () => {
    const u = userEvent.setup()
    usersApi.list.mockResolvedValue(usersResponse([user(1, 'Anna', 'anna@x.hu')]))

    renderSection()
    await screen.findByText(/Anna/)

    await u.click(screen.getByRole('checkbox'))
    expect(screen.getByText('(1 kiválasztva)')).toBeInTheDocument()

    // Új keresés, amiben Anna nem szerepel.
    usersApi.list.mockResolvedValue(usersResponse([user(9, 'Zoltán', 'zoltan@x.hu')]))
    await u.type(screen.getByLabelText('Keresés névre / e-mailre'), 'zol')
    await screen.findByText(/Zoltán/)

    // A kiválasztás nem az aktuális találati oldalhoz kötött.
    expect(screen.getByRole('button', { name: 'Anna eltávolítása' })).toBeInTheDocument()
    expect(screen.getByText('(1 kiválasztva)')).toBeInTheDocument()
  })

  it('a kiválasztott blokk "×" gombja leveszi a jelölést', async () => {
    const u = userEvent.setup()
    sgApi.listUsers.mockResolvedValue(membersResponse([user(1, 'Anna', 'anna@x.hu')]))

    renderSection()
    await screen.findByRole('button', { name: 'Anna eltávolítása' })

    await u.click(screen.getByRole('button', { name: 'Anna eltávolítása' }))

    expect(screen.getByText('(0 kiválasztva)')).toBeInTheDocument()
    expect(screen.getByText('Még nincs kiválasztott tag.')).toBeInTheDocument()
  })
})

describe('SalesGroupMembersSection — mentés', () => {
  it('a teljes kiválasztott halmazt küldi, akkor is, ha a tagok több keresésből jöttek', async () => {
    const u = userEvent.setup()
    // Meglévő tag: Anna (nincs benne a keresési találatokban).
    sgApi.listUsers.mockResolvedValue(membersResponse([user(1, 'Anna', 'anna@x.hu')]))
    usersApi.list.mockResolvedValue(usersResponse([user(9, 'Zoltán', 'zoltan@x.hu')]))
    sgApi.syncUsers.mockResolvedValue(membersResponse([
      user(1, 'Anna', 'anna@x.hu'),
      user(9, 'Zoltán', 'zoltan@x.hu'),
    ]))

    renderSection()
    await screen.findByText(/Zoltán/)

    await u.click(screen.getByRole('checkbox'))          // Zoltán hozzáadása
    await u.click(screen.getByRole('button', { name: 'Tagság mentése' }))

    await waitFor(() => expect(sgApi.syncUsers).toHaveBeenCalledTimes(1))
    // A meglévő tag NEM esik ki azért, mert nincs az aktuális találatok között.
    const [, ids] = sgApi.syncUsers.mock.calls[0]
    expect([...ids].sort()).toEqual([1, 9])
    expect(toast).toHaveBeenCalledWith('Tagság mentve.', 'success')
  })

  it('mentés előtt tiltott a gomb, változás után engedélyezett', async () => {
    const u = userEvent.setup()
    usersApi.list.mockResolvedValue(usersResponse([user(9, 'Zoltán', 'zoltan@x.hu')]))

    renderSection()
    await screen.findByText(/Zoltán/)

    expect(screen.getByRole('button', { name: 'Tagság mentése' })).toBeDisabled()
    await u.click(screen.getByRole('checkbox'))
    expect(screen.getByRole('button', { name: 'Tagság mentése' })).toBeEnabled()
  })

  it('a 422 mezőhibákat összefűzve mutatja', async () => {
    const u = userEvent.setup()
    usersApi.list.mockResolvedValue(usersResponse([user(9, 'Zoltán', 'zoltan@x.hu')]))
    sgApi.syncUsers.mockRejectedValue(apiError(422, {
      errors: { 'user_ids.0': ['A kiválasztott felhasználó nem tagja ennek a cégnek.'] },
    }))

    renderSection()
    await screen.findByText(/Zoltán/)

    await u.click(screen.getByRole('checkbox'))
    await u.click(screen.getByRole('button', { name: 'Tagság mentése' }))

    expect(await screen.findByText('A kiválasztott felhasználó nem tagja ennek a cégnek.')).toBeInTheDocument()
  })
})

describe('SalesGroupMembersSection — jogosultsági módok', () => {
  it('user.view jog nélkül nem keres jelölteket, csak a tagságot mutatja', async () => {
    sgApi.listUsers.mockResolvedValue(membersResponse([user(1, 'Anna', 'anna@x.hu')]))

    renderSection({ canViewUsers: false })

    expect(await screen.findByText(/Anna/)).toBeInTheDocument()
    expect(usersApi.list).not.toHaveBeenCalled()
    expect(screen.queryByLabelText('Keresés névre / e-mailre')).not.toBeInTheDocument()
    expect(screen.getByText(/csak a meglévő tagság látszik/)).toBeInTheDocument()
  })

  it('sales_group.edit jog nélkül nincs mentés gomb és nincs keresés', async () => {
    sgApi.listUsers.mockResolvedValue(membersResponse([user(1, 'Anna', 'anna@x.hu')]))

    renderSection({ canEdit: false })

    expect(await screen.findByText(/Anna/)).toBeInTheDocument()
    expect(usersApi.list).not.toHaveBeenCalled()
    expect(screen.queryByRole('button', { name: 'Tagság mentése' })).not.toBeInTheDocument()
    expect(screen.getByText(/Csak megtekintés/)).toBeInTheDocument()
  })
})

describe('SalesGroupMembersSection — dirty-jelzés a szülőnek', () => {
  it('változásra true-t, mentés után újra false-t jelez', async () => {
    const u = userEvent.setup()
    const onDirtyChange = vi.fn()
    usersApi.list.mockResolvedValue(usersResponse([user(9, 'Zoltán', 'zoltan@x.hu')]))
    sgApi.syncUsers.mockResolvedValue(membersResponse([user(9, 'Zoltán', 'zoltan@x.hu')]))

    renderSection({ onDirtyChange })
    await screen.findByText(/Zoltán/)

    onDirtyChange.mockClear()
    await u.click(screen.getByRole('checkbox'))
    expect(onDirtyChange).toHaveBeenLastCalledWith(true)

    await u.click(screen.getByRole('button', { name: 'Tagság mentése' }))
    await waitFor(() => expect(onDirtyChange).toHaveBeenLastCalledWith(false))
  })
})

describe('SalesGroupMembersSection — teli oldal jelzése', () => {
  it('pontosan 50 találatnál szűkítésre biztat', async () => {
    const list = Array.from({ length: 50 }, (_, i) => user(i + 1, `User ${i + 1}`, `u${i + 1}@x.hu`))
    usersApi.list.mockResolvedValue(usersResponse(list))

    renderSection()

    expect(await screen.findByText(/Csak az első 50 találat látszik/)).toBeInTheDocument()
    const boxes = screen.getAllByRole('checkbox')
    expect(boxes).toHaveLength(50)
    // A régi, 200-as levágásra figyelmeztető szöveg megszűnt.
    expect(screen.queryByText(/200-nál több felhasználója van/)).not.toBeInTheDocument()
  })

  it('49 találatnál nem biztat szűkítésre', async () => {
    const list = Array.from({ length: 49 }, (_, i) => user(i + 1, `User ${i + 1}`, `u${i + 1}@x.hu`))
    usersApi.list.mockResolvedValue(usersResponse(list))

    renderSection()

    await waitFor(() => expect(screen.getAllByRole('checkbox')).toHaveLength(49))
    expect(screen.queryByText(/Csak az első 50 találat látszik/)).not.toBeInTheDocument()
  })
})
