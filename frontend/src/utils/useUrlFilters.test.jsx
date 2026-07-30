import { act, renderHook } from '@testing-library/react'
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
      const [filters, setFilters] = useUrlFilters(defaults)
      return { filters, setFilters, search: useLocation().search }
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
