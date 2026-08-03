import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DateRangePicker from './DateRangePicker'
import { useTranslation } from '../../contexts/TranslationContext'

vi.mock('../../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))

beforeEach(() => {
  useTranslation.mockReturnValue({
    t: (key, params) => (params ? `${key}:${params.name}` : key),
    locale: 'hu-HU',
  })
})

function renderPicker(props = {}) {
  return render(
    <DateRangePicker
      from=""
      to=""
      onApply={vi.fn()}
      open
      onOpenChange={vi.fn()}
      unit="day"
      {...props}
    />,
  )
}

function clearButton() {
  return screen.queryByRole('button', { name: 'reports.clear_range' })
}

describe('DateRangePicker — szűrő törlése', () => {
  it('alapból nincs törlés-gomb (a Kimutatások oldal viselkedése nem változik)', () => {
    renderPicker()
    expect(clearButton()).toBeNull()
  })

  it('clearable módban megjelenik a törlés-gomb', () => {
    renderPicker({ clearable: true, from: '2026-06-01', to: '2026-06-30' })
    expect(clearButton()).toBeInTheDocument()
  })

  it('üres tartománynál a gomb letiltott — nincs mit törölni', () => {
    renderPicker({ clearable: true })
    expect(clearButton()).toBeDisabled()
  })

  it('beállított szűrőnél a gomb aktív', () => {
    renderPicker({ clearable: true, from: '2026-06-01', to: '2026-06-30' })
    expect(clearButton()).toBeEnabled()
  })

  it('kattintásra üres tartományt alkalmaz és bezárja a popovert', async () => {
    const user = userEvent.setup()
    const onApply = vi.fn()
    const onOpenChange = vi.fn()
    renderPicker({ clearable: true, from: '2026-06-01', to: '2026-06-30', onApply, onOpenChange })

    await user.click(clearButton())

    // Ugyanaz a csatorna, mint az "Alkalmaz" — a hívó oldalon nem kell külön ág.
    expect(onApply).toHaveBeenCalledWith('', '')
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('az Alkalmaz továbbra is a kiválasztott tartományt küldi', async () => {
    const user = userEvent.setup()
    const onApply = vi.fn()
    renderPicker({ clearable: true, from: '2026-06-01', to: '2026-06-30', onApply })

    await user.click(screen.getByRole('button', { name: 'reports.apply' }))

    expect(onApply).toHaveBeenCalledWith('2026-06-01', '2026-06-30')
  })
})
