import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BlameFooter from './BlameFooter'
import { useTranslation } from '../contexts/TranslationContext'

vi.mock('../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))

// A valódi fordítások helyett rögzített szótár: a teszt a komponens
// állapot-logikáját méri, nem a TranslationSeeder tartalmát.
const strings = {
  'blame.created_by':   'Létrehozta',
  'blame.updated_by':   'Módosította',
  'blame.system':       'Rendszer',
  'blame.unknown_user': '—',
}

beforeEach(() => {
  useTranslation.mockReturnValue({ t: (key) => strings[key] ?? key })
})

describe('BlameFooter', () => {
  it('nem renderel semmit, ha egyik blame-kulcs sincs a válaszban (új rekord)', () => {
    const { container } = render(<BlameFooter />)
    expect(container).toBeEmptyDOMElement()
  })

  it('null érték esetén "Rendszer"-t ír, időpont nélkül', () => {
    render(<BlameFooter createdBy={null} updatedBy={null} />)
    expect(screen.getByText('Létrehozta: Rendszer')).toBeInTheDocument()
    expect(screen.getByText('Módosította: Rendszer')).toBeInTheDocument()
  })

  it('feloldott felhasználót névvel és YYYY-MM-DD HH:mm időbélyeggel mutat', () => {
    // Helyi idő adott, hogy a teszt ne függjön a futtató időzónájától.
    render(
      <BlameFooter
        createdBy={{ name: 'Kovács János', at: '2026-07-28T19:34:01' }}
        updatedBy={{ name: 'Nagy Éva',     at: '2026-07-29T08:05:00' }}
      />,
    )
    expect(screen.getByText('Létrehozta: Kovács János · 2026-07-28 19:34')).toBeInTheDocument()
    expect(screen.getByText('Módosította: Nagy Éva · 2026-07-29 08:05')).toBeInTheDocument()
  })

  it('törölt felhasználónál semleges jelölőt tesz a név helyére, az időpontot megtartja', () => {
    render(<BlameFooter createdBy={{ name: null, at: '2026-07-28T19:34:01' }} />)
    expect(screen.getByText('Létrehozta: — · 2026-07-28 19:34')).toBeInTheDocument()
  })

  it('csak azt a sort rendereli, amelyik kulcs jelen van', () => {
    render(<BlameFooter updatedBy={{ name: 'Nagy Éva', at: '2026-07-29T08:05:00' }} />)
    expect(screen.queryByText(/Létrehozta/)).not.toBeInTheDocument()
    expect(screen.getByText('Módosította: Nagy Éva · 2026-07-29 08:05')).toBeInTheDocument()
  })
})
