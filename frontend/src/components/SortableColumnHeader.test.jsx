import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SortableColumnHeader from './SortableColumnHeader'
import { useTranslation } from '../contexts/TranslationContext'

vi.mock('../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))

beforeEach(() => {
  useTranslation.mockReturnValue({
    t: (key, params) => (params ? `${key}:${params.name}` : key),
  })
})

// A komponens <th>-t rendel, ezért érvényes táblázat-szerkezetbe kell ágyazni —
// enélkül a React DOM-nesting figyelmeztetést adna, és az `aria-sort` sem
// értelmezhető.
function renderHeader(props = {}) {
  return render(
    <table>
      <thead>
        <tr>
          <SortableColumnHeader label="Partner" onSort={vi.fn()} {...props} />
        </tr>
      </thead>
    </table>,
  )
}

describe('SortableColumnHeader', () => {
  it('az oszlopnevet jeleníti meg', () => {
    renderHeader()
    expect(screen.getByRole('button')).toHaveTextContent('Partner')
  })

  it('rendezetlen állapotban aria-sort="none"', () => {
    renderHeader({ direction: null })
    expect(screen.getByRole('columnheader')).toHaveAttribute('aria-sort', 'none')
  })

  it('növekvő rendezésnél aria-sort="ascending"', () => {
    renderHeader({ direction: 'asc' })
    expect(screen.getByRole('columnheader')).toHaveAttribute('aria-sort', 'ascending')
  })

  it('csökkenő rendezésnél aria-sort="descending"', () => {
    renderHeader({ direction: 'desc' })
    expect(screen.getByRole('columnheader')).toHaveAttribute('aria-sort', 'descending')
  })

  it('a gomb aria-labelje a MŰVELETET nevezi meg, az oszlopnévvel', () => {
    // Az irányt szándékosan nem ismétli meg — azt az aria-sort közli.
    renderHeader()
    expect(screen.getByRole('button')).toHaveAccessibleName('columns.sort_button:Partner')
  })

  it('kattintásra jelzi a rendezés-szándékot (az irányt a hook dönti el)', async () => {
    const user = userEvent.setup()
    const onSort = vi.fn()
    renderHeader({ onSort })

    await user.click(screen.getByRole('button'))

    expect(onSort).toHaveBeenCalledTimes(1)
    // A komponens semmilyen irány-argumentumot nem ad át: a ciklus a hookban él.
    expect(onSort).toHaveBeenCalledWith(expect.anything())
  })

  it('aktív rendezésnél megkapja az is-active jelölést, egyébként nem', () => {
    const { unmount } = renderHeader({ direction: 'asc' })
    expect(screen.getByRole('button').className).toContain('is-active')
    unmount()

    renderHeader({ direction: null })
    expect(screen.getByRole('button').className).not.toContain('is-active')
  })

  it('jobbra igazított oszlopnál a cella és a gomb is jobbra igazít', () => {
    renderHeader({ align: 'right' })

    expect(screen.getByRole('columnheader')).toHaveClass('text-right')
    expect(screen.getByRole('button').className).toContain('sortable-th--right')
  })

  it('igazítás nélkül nem tesz rá igazító osztályt', () => {
    renderHeader()

    expect(screen.getByRole('columnheader')).not.toHaveClass('text-right')
    expect(screen.getByRole('button').className).not.toContain('sortable-th--right')
  })
})
