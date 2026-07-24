import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ColumnPicker from './ColumnPicker'
import { useTranslation } from '../contexts/TranslationContext'
import { usePopoverDismiss } from '../utils/usePopoverDismiss'

vi.mock('../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))
vi.mock('../utils/usePopoverDismiss', () => ({ usePopoverDismiss: vi.fn() }))

// Egyszerű, determinisztikus t(): kulcs önmagában, paraméterezve pedig
// "kulcs érték1/érték2/..." alakban — így a teszt a tényleges számokat/neveket
// tudja a szövegben ellenőrizni anélkül, hogy a valódi fordítási kulcsokat kellene ismernie.
function t(key, params) {
  return params ? `${key} ${Object.values(params).join('/')}` : key
}

beforeEach(() => {
  useTranslation.mockReturnValue({ t })
  usePopoverDismiss.mockReset()
})

// 4 oszlop: elöl+hátul locked ('name'/'actions'), két nem-locked ('email'/'status'),
// az 14 lista tényleges registry-mintáját követve (l. useListColumns.test.js fixture-jei).
const COLUMNS = [
  { key: 'name', label: 'Name', locked: true },
  { key: 'email', label: 'Email' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', locked: true },
]

function setup(overrides = {}) {
  const props = {
    columns: COLUMNS,
    isVisible: (key) => key !== 'status',
    onToggle: vi.fn(),
    onReorder: vi.fn(),
    onReset: vi.fn(),
    isDirty: false,
    open: true,
    onOpenChange: vi.fn(),
    id: 'test-columns',
    ...overrides,
  }
  const utils = render(<ColumnPicker {...props} />)
  return { ...utils, props }
}

describe('trigger gomb', () => {
  it('zárt állapotban aria-expanded=false, nincs popover-panel', () => {
    setup({ open: false })
    const trigger = screen.getByRole('button', { name: 'columns.picker_button' })
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('kattintásra jelzi a szülőnek a nyitást (onOpenChange(true))', () => {
    const { props } = setup({ open: false })
    fireEvent.click(screen.getByRole('button', { name: 'columns.picker_button' }))
    expect(props.onOpenChange).toHaveBeenCalledWith(true)
  })

  it('nyitott állapotban aria-expanded=true, kattintásra zárást jelez (onOpenChange(false))', () => {
    const { props } = setup({ open: true })
    const trigger = screen.getByRole('button', { name: 'columns.picker_button' })
    expect(trigger).toHaveAttribute('aria-expanded', 'true')

    fireEvent.click(trigger)
    expect(props.onOpenChange).toHaveBeenCalledWith(false)
  })
})

describe('popover tartalom (nyitott állapotban)', () => {
  it('megjeleníti a látható/összes oszlop számlálót', () => {
    // isVisible: 'status' rejtett, a másik 3 (name/email/actions) látható → 3/4
    setup()
    expect(screen.getByText('columns.visible_count 3/4')).toBeInTheDocument()
  })

  it('a lista a várt oszlop-feliratokkal jelenik meg', () => {
    setup()
    for (const col of COLUMNS) {
      expect(screen.getByText(col.label)).toBeInTheDocument()
    }
  })
})

describe('checkbox — láthatóság toggle', () => {
  it('nem-locked oszlop checkbox kattintása a helyes kulccsal hívja a toggle-t', () => {
    const { props } = setup()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Email' }))
    expect(props.onToggle).toHaveBeenCalledWith('email')
  })

  it('a checkbox checked-állapota az isVisible()-t tükrözi', () => {
    setup()
    expect(screen.getByRole('checkbox', { name: 'Email' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Status' })).not.toBeChecked()
  })

  it('locked oszlop checkboxa disabled, kattintásra NEM hívja a toggle-t', async () => {
    // FONTOS: itt szándékosan userEvent, NEM fireEvent — a fireEvent.click()
    // egy nyers 'click' DOM-eseményt dispatchel, amit jsdom checkbox-on
    // (a <button disabled>-tól eltérően) nem szűr ki, ezért az onChange
    // lefutna disabled input esetén is (hamis negatívot adva a tesztre, NEM a
    // komponensre — valós böngészőben a disabled checkbox blokkolja a
    // kattintást). A userEvent.click() a `disabled` állapotot explicit
    // ellenőrzi kattintás előtt, így hűen szimulálja a valós felhasználói
    // interakciót.
    const user = userEvent.setup()
    const { props } = setup()
    const checkbox = screen.getByRole('checkbox', { name: 'Name' })
    expect(checkbox).toBeDisabled()

    await user.click(checkbox)
    expect(props.onToggle).not.toHaveBeenCalled()
  })

  it('locked sor title-tooltipet kap, nem-locked nem', () => {
    setup()
    const lockedRow = screen.getByRole('checkbox', { name: 'Name' }).closest('.column-picker-row')
    const unlockedRow = screen.getByRole('checkbox', { name: 'Email' }).closest('.column-picker-row')
    expect(lockedRow).toHaveAttribute('title', 'columns.locked_tooltip')
    expect(unlockedRow).not.toHaveAttribute('title')
  })
})

describe('drag-fogantyú', () => {
  it('nem-locked sorok kapnak fogantyút, értelmes aria-label-lel', () => {
    setup()
    expect(screen.getByRole('button', { name: 'columns.drag_handle Email' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'columns.drag_handle Status' })).toBeInTheDocument()
  })

  it('locked sorok NEM kapnak fogantyút', () => {
    setup()
    expect(screen.queryByRole('button', { name: 'columns.drag_handle Name' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'columns.drag_handle Actions' })).not.toBeInTheDocument()
    // összesen csak a 2 nem-locked oszlopnak van fogantyúja
    expect(screen.getAllByRole('button', { name: /^columns\.drag_handle /i })).toHaveLength(2)
  })
})

describe('"Alapértelmezett visszaállítása"', () => {
  it('kattintásra hívja a reset-et, ha isDirty', () => {
    const { props } = setup({ isDirty: true })
    const resetBtn = screen.getByRole('button', { name: 'columns.reset' })
    expect(resetBtn).not.toBeDisabled()

    fireEvent.click(resetBtn)
    expect(props.onReset).toHaveBeenCalledTimes(1)
  })

  it('disabled, ha nincs eltérés a defaulttól (isDirty=false) — kattintásra sem hív reset-et', () => {
    const { props } = setup({ isDirty: false })
    const resetBtn = screen.getByRole('button', { name: 'columns.reset' })
    expect(resetBtn).toBeDisabled()

    fireEvent.click(resetBtn)
    expect(props.onReset).not.toHaveBeenCalled()
  })
})

describe('usePopoverDismiss bekötése (a hook belső logikáját NEM teszteljük, csak a bekötést)', () => {
  it('a komponens az open prop-ot, egy záró callback-et és két DOM-ref-et ad át', () => {
    setup({ open: true })
    expect(usePopoverDismiss).toHaveBeenCalledTimes(1)
    const [openArg, closeFn, containerRef, triggerRef] = usePopoverDismiss.mock.calls[0]

    expect(openArg).toBe(true)
    expect(typeof closeFn).toBe('function')
    expect(containerRef.current).toBeInstanceOf(HTMLElement)
    expect(triggerRef.current).toBeInstanceOf(HTMLButtonElement)
  })

  it('a záró callback meghívása onOpenChange(false)-t vált ki', () => {
    const { props } = setup({ open: true })
    const closeFn = usePopoverDismiss.mock.calls[0][1]

    closeFn()
    expect(props.onOpenChange).toHaveBeenCalledWith(false)
  })
})
