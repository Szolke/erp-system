import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Pagination from './Pagination'
import { useTranslation } from '../contexts/TranslationContext'

vi.mock('../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))

// Rögzített szótár a valódi fordítások helyett: a teszt a lapozó-logikát méri,
// nem a TranslationSeeder tartalmát.
const strings = {
  'common.prev_page': 'Előző',
  'common.next_page': 'Következő',
}

beforeEach(() => {
  useTranslation.mockReturnValue({ t: (key) => strings[key] ?? key })
})

/** A megjelenített oldalszámok, a fix Előző/Következő gombok nélkül. */
function visiblePages() {
  return screen
    .getAllByRole('button')
    .map((btn) => btn.textContent)
    .filter((label) => label !== 'Előző' && label !== 'Következő')
}

describe('Pagination — mikor jelenik meg', () => {
  it('meta nélkül nem renderel semmit', () => {
    const { container } = render(<Pagination meta={null} onChange={vi.fn()} />)
    expect(container).toBeEmptyDOMElement()
  })

  it('egyetlen oldalnál nem renderel semmit (nincs mit lapozni)', () => {
    const { container } = render(
      <Pagination meta={{ current_page: 1, last_page: 1 }} onChange={vi.fn()} />,
    )
    expect(container).toBeEmptyDOMElement()
  })

  it('két oldaltól már megjelenik', () => {
    render(<Pagination meta={{ current_page: 1, last_page: 2 }} onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'Következő' })).toBeInTheDocument()
  })
})

describe('Pagination — oldalszám-lista és ellipszis', () => {
  it('legfeljebb 7 oldalnál mindet kiírja, ellipszis nélkül', () => {
    render(<Pagination meta={{ current_page: 4, last_page: 7 }} onChange={vi.fn()} />)

    expect(visiblePages()).toEqual(['1', '2', '3', '4', '5', '6', '7'])
    expect(screen.queryByText('…')).not.toBeInTheDocument()
  })

  it('7 fölött összecsukja: a közepén két ellipszis, körülötte szomszédok', () => {
    render(<Pagination meta={{ current_page: 10, last_page: 20 }} onChange={vi.fn()} />)

    expect(visiblePages()).toEqual(['1', '9', '10', '11', '20'])
    expect(screen.getAllByText('…')).toHaveLength(2)
  })

  it('a lista ELEJÉN nincs bevezető ellipszis (nincs mit kihagyni)', () => {
    render(<Pagination meta={{ current_page: 2, last_page: 20 }} onChange={vi.fn()} />)

    expect(visiblePages()).toEqual(['1', '2', '3', '20'])
    expect(screen.getAllByText('…')).toHaveLength(1)
  })

  it('a lista VÉGÉN sincs záró ellipszis', () => {
    render(<Pagination meta={{ current_page: 19, last_page: 20 }} onChange={vi.fn()} />)

    expect(visiblePages()).toEqual(['1', '18', '19', '20'])
    expect(screen.getAllByText('…')).toHaveLength(1)
  })

  it('nem duplázza az oldalszámot, ha a szomszéd egybeesik az első/utolsó oldallal', () => {
    // current=1 mellett a "current - 1" = 0 kiesik, a "current" pedig maga az
    // első oldal — a Set-es dedup nélkül "1" kétszer jelenne meg.
    render(<Pagination meta={{ current_page: 1, last_page: 20 }} onChange={vi.fn()} />)

    expect(visiblePages()).toEqual(['1', '2', '20'])
  })
})

describe('Pagination — gombok állapota', () => {
  it('az aktuális oldal gombja letiltott és kiemelt', () => {
    render(<Pagination meta={{ current_page: 10, last_page: 20 }} onChange={vi.fn()} />)

    const current = screen.getByRole('button', { name: '10' })
    expect(current).toBeDisabled()
    expect(current).toHaveClass('btn-primary')
  })

  it('a nem aktuális oldal gombja aktív és másodlagos stílusú', () => {
    render(<Pagination meta={{ current_page: 10, last_page: 20 }} onChange={vi.fn()} />)

    const other = screen.getByRole('button', { name: '11' })
    expect(other).toBeEnabled()
    expect(other).toHaveClass('btn-secondary')
  })

  it('az első oldalon az Előző letiltott, a Következő aktív', () => {
    render(<Pagination meta={{ current_page: 1, last_page: 20 }} onChange={vi.fn()} />)

    expect(screen.getByRole('button', { name: 'Előző' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Következő' })).toBeEnabled()
  })

  it('az utolsó oldalon a Következő letiltott, az Előző aktív', () => {
    render(<Pagination meta={{ current_page: 20, last_page: 20 }} onChange={vi.fn()} />)

    expect(screen.getByRole('button', { name: 'Következő' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Előző' })).toBeEnabled()
  })
})

describe('Pagination — onChange', () => {
  it('oldalszámra kattintva az adott oldalt kéri, számként', async () => {
    const onChange = vi.fn()
    render(<Pagination meta={{ current_page: 10, last_page: 20 }} onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '20' }))

    expect(onChange).toHaveBeenCalledWith(20)
  })

  it('az Előző az aktuálisnál eggyel kisebb oldalt kér', async () => {
    const onChange = vi.fn()
    render(<Pagination meta={{ current_page: 10, last_page: 20 }} onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: 'Előző' }))

    expect(onChange).toHaveBeenCalledWith(9)
  })

  it('a Következő az aktuálisnál eggyel nagyobb oldalt kér', async () => {
    const onChange = vi.fn()
    render(<Pagination meta={{ current_page: 10, last_page: 20 }} onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: 'Következő' }))

    expect(onChange).toHaveBeenCalledWith(11)
  })

  it('az aktuális oldal gombja nem hív onChange-t (felesleges újratöltés)', async () => {
    const onChange = vi.fn()
    render(<Pagination meta={{ current_page: 10, last_page: 20 }} onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '10' }))

    expect(onChange).not.toHaveBeenCalled()
  })
})
