import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PerPageSelector from './PerPageSelector'
import { useTranslation } from '../contexts/TranslationContext'

vi.mock('../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))

beforeEach(() => {
  useTranslation.mockReturnValue({ t: (key) => (key === 'common.rows' ? 'Sorok' : key) })
})

describe('PerPageSelector', () => {
  it('az öt rögzített lapméretet kínálja fel, ebben a sorrendben', () => {
    render(<PerPageSelector value={20} onChange={vi.fn()} />)

    const options = screen.getAllByRole('option').map((o) => o.textContent)
    expect(options).toEqual(['20', '50', '100', '200', '500'])
  })

  it('nem kínál a menthető felső határnál (500) nagyobb lapméretet', () => {
    // A lapméret a user_list_preferences rétegbe is mentődik, ahol a backend
    // validáció `between:5,500` — egy nagyobb opció 422-vel elszállna.
    render(<PerPageSelector value={20} onChange={vi.fn()} />)

    const options = screen.getAllByRole('option').map((o) => Number(o.textContent))
    expect(Math.max(...options)).toBeLessThanOrEqual(500)
  })

  it('az aktuális értéket jelöli ki a legördülőben', () => {
    render(<PerPageSelector value={100} onChange={vi.fn()} />)

    expect(screen.getByRole('combobox')).toHaveValue('100')
  })

  it('a feliratot a fordításból veszi', () => {
    render(<PerPageSelector value={20} onChange={vi.fn()} />)

    expect(screen.getByText(/Sorok/)).toBeInTheDocument()
  })

  it('választáskor SZÁMOT ad tovább, nem a select string-értékét', async () => {
    // A lapméret a lista API-hívás `per_page` paraméterébe megy; a Number()
    // konverzió nélkül "100" menne, ami a hívó oldali összehasonlításokat
    // (value === n) csendben elrontaná.
    const onChange = vi.fn()
    render(<PerPageSelector value={20} onChange={onChange} />)

    await userEvent.selectOptions(screen.getByRole('combobox'), '100')

    expect(onChange).toHaveBeenCalledWith(100)
    expect(typeof onChange.mock.calls[0][0]).toBe('number')
  })
})
