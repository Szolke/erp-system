import { useEffect } from 'react'
import { act, render, renderHook, screen } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { useUrlFilters } from './useUrlFilters'

// A hook a URL query stringgel dolgozik, ezért MemoryRouter-be ágyazzuk, és a
// hook eredménye mellett a TÉNYLEGES URL-t is visszaadjuk — a szerződés fele
// épp az, hogy mit ír vissza a címsorba (megosztható, frissítés-biztos link).

function renderFilters(defaults, initialEntry = '/kimutatasok') {
  const wrapper = ({ children }) => (
    <MemoryRouter initialEntries={[initialEntry]}>{children}</MemoryRouter>
  )

  return renderHook(
    () => {
      const [filters, setFilters, explicitKeys] = useUrlFilters(defaults)
      return { filters, setFilters, explicitKeys, search: useLocation().search }
    },
    { wrapper },
  )
}

const params = (search) => new URLSearchParams(search)

describe('useUrlFilters — induló feltöltés', () => {
  it('a hiányzó kulcsokat a defaults-ból tölti fel', () => {
    const { result } = renderFilters({ from: '2026-07', to: '2026-07' })

    expect(result.current.filters).toEqual({ from: '2026-07', to: '2026-07' })
  })

  it('a defaultokat VISSZA is írja a URL-be, nem csak memóriában tartja', () => {
    // Enélkül egy megosztott link idővel más időszakot jelentene: a "mai hónap"
    // a küldő és a fogadó gépén eltérhet.
    const { result } = renderFilters({ from: '2026-07', to: '2026-07' })

    expect(params(result.current.search).get('from')).toBe('2026-07')
    expect(params(result.current.search).get('to')).toBe('2026-07')
  })

  it('a URL-ben MEGLÉVŐ érték erősebb a defaultnál, a hiányzó mellette kiegészül', () => {
    const { result } = renderFilters(
      { from: '2026-07', to: '2026-07' },
      '/kimutatasok?from=2026-01',
    )

    expect(result.current.filters).toEqual({ from: '2026-01', to: '2026-07' })
    expect(params(result.current.search).get('from')).toBe('2026-01')
  })

  it('a nem kezelt query-paramétereket a feltöltés megtartja', () => {
    const { result } = renderFilters({ from: '2026-07' }, '/kimutatasok?tab=ertekesites')

    expect(params(result.current.search).get('tab')).toBe('ertekesites')
    expect(params(result.current.search).get('from')).toBe('2026-07')
  })
})

describe('useUrlFilters — explicit kulcsok a megnyitáskor', () => {
  // A lapméret-feloldás (URL > mentett preferencia > default) ezen múlik: a
  // defaultok visszaírása után a `filters` már nem különbözteti meg a
  // megosztott linkből érkező értéket a default-feltöltéstől.
  it('csak a URL-ben TÉNYLEGESEN ott álló kulcsokat tartalmazza, a feltöltötteket nem', () => {
    const { result } = renderFilters({ from: '2026-07', per_page: '20' }, '/lista?from=2026-01')

    expect(result.current.explicitKeys.has('from')).toBe(true)
    expect(result.current.explicitKeys.has('per_page')).toBe(false)
  })

  it('a halmaz a későbbi setFilters-írásoktól sem bővül (csak a megnyitás számít)', () => {
    const { result } = renderFilters({ per_page: '20' })

    act(() => { result.current.setFilters({ per_page: '100' }) })

    expect(result.current.explicitKeys.has('per_page')).toBe(false)
  })
})

describe('useUrlFilters — a feltöltéssel egy commitban futó írás', () => {
  // A lapozó listák mount-ján két írás fut egyszerre: a default-feltöltés és a
  // mentett lapméret URL-be szinkronizálása. Rögzítjük, mi lesz az eredmény —
  // a lényeg, hogy a megnyitáskor a URL-ben ÁLLÓ (user által hozott) kulcsok
  // ne vesszenek el.
  function PageSizeSyncProbe() {
    const [filters, setFilters] = useUrlFilters({ type: '', per_page: '20', page: '1' })
    useEffect(() => {
      if (filters.per_page !== '100') setFilters({ per_page: '100' })
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])
    return <div data-testid="url">{useLocation().search}</div>
  }

  it('a megosztott linkből hozott kulcsok megmaradnak, a feltöltött üres defaultok elesnek', () => {
    render(
      <MemoryRouter initialEntries={['/lista?page=3']}>
        <PageSizeSyncProbe />
      </MemoryRouter>,
    )

    const url = params(screen.getByTestId('url').textContent)
    expect(url.get('per_page')).toBe('100')
    expect(url.get('page')).toBe('3')
    expect(url.has('type')).toBe(false)
  })
})

describe('useUrlFilters — setFilters', () => {
  it('a megadott kulcsot frissíti a szűrőkben és a URL-ben is', () => {
    const { result } = renderFilters({ from: '2026-07', to: '2026-07' })

    act(() => { result.current.setFilters({ from: '2025-03' }) })

    expect(result.current.filters.from).toBe('2025-03')
    expect(params(result.current.search).get('from')).toBe('2025-03')
  })

  it('részleges patch a többi kulcsot érintetlenül hagyja', () => {
    const { result } = renderFilters({ from: '2026-07', to: '2026-07' })

    act(() => { result.current.setFilters({ from: '2025-03' }) })

    expect(result.current.filters.to).toBe('2026-07')
  })

  it('több kulcsot egyszerre is beállít', () => {
    const { result } = renderFilters({ from: '2026-07', to: '2026-07' })

    act(() => { result.current.setFilters({ from: '2025-01', to: '2025-12' }) })

    expect(result.current.filters).toEqual({ from: '2025-01', to: '2025-12' })
  })

  it('üres string TÖRLI a kulcsot a URL-ből, a szűrő pedig a defaultra esik vissza', () => {
    const { result } = renderFilters({ from: '2026-07', to: '2026-07' })

    act(() => { result.current.setFilters({ from: '' }) })

    expect(params(result.current.search).has('from')).toBe(false)
    expect(result.current.filters.from).toBe('2026-07')
  })

  it('a null és az undefined ugyanígy törli a kulcsot', () => {
    const { result } = renderFilters({ from: '2026-07', to: '2026-07' })

    act(() => { result.current.setFilters({ from: null, to: undefined }) })

    expect(params(result.current.search).has('from')).toBe(false)
    expect(params(result.current.search).has('to')).toBe(false)
  })

  it('a nem kezelt query-paramétereket megőrzi', () => {
    // A riport-fülek a `tab` paramétert a szűrőkön kívül tartják — egy
    // szűrő-váltás nem dobhatja el a fül-választást.
    const { result } = renderFilters({ from: '2026-07' }, '/kimutatasok?tab=ertekesites')

    act(() => { result.current.setFilters({ from: '2025-03' }) })

    expect(params(result.current.search).get('tab')).toBe('ertekesites')
  })

  it('a defaults-ban nem szereplő kulcsot is kiírja a URL-be', () => {
    const { result } = renderFilters({ from: '2026-07' })

    act(() => { result.current.setFilters({ partner_id: 42 }) })

    expect(params(result.current.search).get('partner_id')).toBe('42')
  })
})
