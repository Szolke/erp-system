import { useRef } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { usePopoverDismiss } from './usePopoverDismiss'

// A `ColumnPicker.test.jsx` szándékosan CSAK a hook BEKÖTÉSÉT ellenőrzi (ott
// mockolva van) — a hook belső viselkedése itt kap fedezetet, saját, minimális
// harness-szel. Így a két teszt nem fedi egymást.

function Harness({ open, onClose }) {
  const containerRef = useRef(null)
  const triggerRef = useRef(null)

  usePopoverDismiss(open, onClose, containerRef, triggerRef)

  return (
    <div>
      <div ref={containerRef}>
        <button ref={triggerRef}>Nyitó gomb</button>
        {open && <div role="dialog"><button>Panel gomb</button></div>}
      </div>
      <button>Kívüli gomb</button>
    </div>
  )
}

let onClose

beforeEach(() => {
  onClose = vi.fn()
})

describe('usePopoverDismiss — kívülre kattintás', () => {
  it('nyitott állapotban a konténeren KÍVÜLI kattintás zár', () => {
    render(<Harness open onClose={onClose} />)

    fireEvent.mouseDown(screen.getByRole('button', { name: 'Kívüli gomb' }))

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('a konténeren BELÜLI kattintás nem zár (a panel használható marad)', () => {
    render(<Harness open onClose={onClose} />)

    fireEvent.mouseDown(screen.getByRole('button', { name: 'Panel gomb' }))
    fireEvent.mouseDown(screen.getByRole('button', { name: 'Nyitó gomb' }))

    expect(onClose).not.toHaveBeenCalled()
  })

  it('zárt állapotban nem figyel: a kívülre kattintás nem hív onClose-t', () => {
    // Enélkül minden zárt popover feleslegesen futtatná a handlerét minden
    // dokumentum-szintű kattintásra.
    render(<Harness open={false} onClose={onClose} />)

    fireEvent.mouseDown(screen.getByRole('button', { name: 'Kívüli gomb' }))

    expect(onClose).not.toHaveBeenCalled()
  })
})

describe('usePopoverDismiss — billentyűzet', () => {
  it('Escape zár', () => {
    render(<Harness open onClose={onClose} />)

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('Escape után a fókusz visszakerül a nyitó gombra', () => {
    // Billentyűzetes navigációnál enélkül a fókusz a törölt panelen maradna,
    // és a Tab a dokumentum elejéről indulna újra.
    render(<Harness open onClose={onClose} />)
    screen.getByRole('button', { name: 'Panel gomb' }).focus()

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(screen.getByRole('button', { name: 'Nyitó gomb' })).toHaveFocus()
  })

  it('más billentyűre nem zár', () => {
    render(<Harness open onClose={onClose} />)

    fireEvent.keyDown(document, { key: 'Enter' })
    fireEvent.keyDown(document, { key: 'a' })

    expect(onClose).not.toHaveBeenCalled()
  })

  it('zárt állapotban az Escape sem hív onClose-t', () => {
    render(<Harness open={false} onClose={onClose} />)

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(onClose).not.toHaveBeenCalled()
  })
})

describe('usePopoverDismiss — takarítás', () => {
  it('unmount után a dokumentum-szintű figyelők leválnak', () => {
    const { unmount } = render(<Harness open onClose={onClose} />)
    unmount()

    fireEvent.mouseDown(document.body)
    fireEvent.keyDown(document, { key: 'Escape' })

    expect(onClose).not.toHaveBeenCalled()
  })

  it('bezáráskor (open → false) a figyelők leválnak', () => {
    const { rerender } = render(<Harness open onClose={onClose} />)
    rerender(<Harness open={false} onClose={onClose} />)

    fireEvent.mouseDown(document.body)

    expect(onClose).not.toHaveBeenCalled()
  })
})
