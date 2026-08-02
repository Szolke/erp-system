import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ColumnPicker from './ColumnPicker'
import { useTranslation } from '../contexts/TranslationContext'
import { usePopoverDismiss } from '../utils/usePopoverDismiss'

vi.mock('../contexts/TranslationContext', () => ({ useTranslation: vi.fn() }))
vi.mock('../utils/usePopoverDismiss', () => ({ usePopoverDismiss: vi.fn() }))

// A drag&drop GESZTUS jsdom alatt nem szimulálható hűen: a dnd-kit ütközés-
// detektálása (closestCenter) a getBoundingClientRect-re épül, ami jsdom-ban
// minden elemre 0×0-t ad, így az `over` célpont nem determinisztikus. Ezért a
// VALÓDI DndContext renderelődik tovább (a useSortable így működőképes marad) —
// csak az `onDragEnd` propot csípjük le, hogy a komponens SAJÁT
// handleDragEnd-jét futtathassuk valósághű esemény-objektumokkal. Ugyanaz az
// elv, mint a usePopoverDismiss-nél lentebb: a külső könyvtár belső működését
// nem teszteljük, a bekötést igen.
const dnd = vi.hoisted(() => ({ props: null }))

vi.mock('@dnd-kit/core', async (importOriginal) => {
  const actual = await importOriginal()
  const Actual = actual.DndContext
  return {
    ...actual,
    DndContext: (props) => {
      dnd.props = props
      return <Actual {...props} />
    },
  }
})

// Egyszerű, determinisztikus t(): kulcs önmagában, paraméterezve pedig
// "kulcs érték1/érték2/..." alakban — így a teszt a tényleges számokat/neveket
// tudja a szövegben ellenőrizni anélkül, hogy a valódi fordítási kulcsokat kellene ismernie.
function t(key, params) {
  return params ? `${key} ${Object.values(params).join('/')}` : key
}

beforeEach(() => {
  useTranslation.mockReturnValue({ t })
  usePopoverDismiss.mockReset()
  dnd.props = null
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

  it('a számláló a locked oszlopokat is beleszámolja, ha minden kapcsolható oszlop rejtett', () => {
    // A két locked oszlop elrejthetetlen, ezért a számláló soha nem eshet 0-ra.
    setup({ isVisible: (key) => key === 'name' || key === 'actions' })
    expect(screen.getByText('columns.visible_count 2/4')).toBeInTheDocument()
  })

  it('minden oszlop láthatóságakor a két szám megegyezik', () => {
    setup({ isVisible: () => true })
    expect(screen.getByText('columns.visible_count 4/4')).toBeInTheDocument()
  })

  it('a panel dialog szerepet és nevet kap, a trigger pedig a kapott id-t', () => {
    setup()
    expect(screen.getByRole('dialog', { name: 'columns.picker_button' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'columns.picker_button' })).toHaveAttribute('id', 'test-columns')
  })
})

describe('panel-horgonyzás (align)', () => {
  it('alapból BALRA horgonyzott — nem kapja meg a jobbra-módosítót', () => {
    // Az alapértelmezés szándékosan a bal horgonyzás: a trigger jellemzően egy
    // bal oldali eszköztár-sáv eleje, ahol a jobbra-horgonyzás a sidebar alá
    // lógatná a panelt (l. a komponens doc-kommentjét).
    setup()
    expect(screen.getByRole('dialog')).not.toHaveClass('fc-popover-panel--right')
  })

  it('align="right" esetén megkapja a jobbra-horgonyzó módosítót', () => {
    setup({ align: 'right' })
    expect(screen.getByRole('dialog')).toHaveClass('fc-popover-panel--right')
  })
})

describe('sorrendezés — a drag-vég bekötése (handleDragEnd)', () => {
  it('érvényes ejtésnél a két oszlopkulccsal hívja az onReorder-t', () => {
    const { props } = setup()
    dnd.props.onDragEnd({ active: { id: 'email' }, over: { id: 'status' } })
    expect(props.onReorder).toHaveBeenCalledWith('email', 'status')
  })

  it('a panelen kívülre ejtve (over = null) nem hív reorder-t', () => {
    const { props } = setup()
    dnd.props.onDragEnd({ active: { id: 'email' }, over: null })
    expect(props.onReorder).not.toHaveBeenCalled()
  })

  it('helyben ejtve (active = over) nem hív reorder-t', () => {
    // Egy megfogott, de el nem mozdított sor nem indíthat mentést a hookban.
    const { props } = setup()
    dnd.props.onDragEnd({ active: { id: 'email' }, over: { id: 'email' } })
    expect(props.onReorder).not.toHaveBeenCalled()
  })

  it('a sortable halmaz CSAK a nem-locked oszlopokat tartalmazza', () => {
    // A locked pozíciókat a hook úgyis fail-safe visszakényszeríti, de a
    // komponens már eleve ki sem ajánlja őket ejtési célpontnak.
    setup()
    const sortableIds = dnd.props.children.props.items
    expect(sortableIds).toEqual(['email', 'status'])
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

// A guard küszöbét a hook adja (MIN_VISIBLE_COLUMNS = 1); itt a KOMPONENS
// oldali következményét ellenőrizzük: az utolsó látható oszlop checkboxa
// letiltódik. Locked oszlop nélküli registry kell hozzá, mert a valós listák
// locked oszlopa eleve tartja a minimumot (l. useListColumns.test.js).
const COLUMNS_NO_LOCKED = [
  { key: 'code', label: 'Code' },
  { key: 'name', label: 'Name2' },
]

describe('minimum-oszlop guard — az utolsó látható oszlop nem kapcsolható ki', () => {
  it('egyetlen látható oszlopnál annak checkboxa disabled, és kattintásra sem hív toggle-t', async () => {
    // userEvent (nem fireEvent) — l. a locked checkbox tesztjének indoklását:
    // a disabled állapotot csak a userEvent ellenőrzi kattintás előtt.
    const user = userEvent.setup()
    const { props } = setup({ columns: COLUMNS_NO_LOCKED, isVisible: (key) => key === 'code' })

    const lastVisible = screen.getByRole('checkbox', { name: 'Code' })
    expect(lastVisible).toBeChecked()
    expect(lastVisible).toBeDisabled()

    await user.click(lastVisible)
    expect(props.onToggle).not.toHaveBeenCalled()
  })

  it('a REJTETT oszlopok checkboxa a minimumon is aktív marad (visszakapcsolhatók)', async () => {
    const user = userEvent.setup()
    const { props } = setup({ columns: COLUMNS_NO_LOCKED, isVisible: (key) => key === 'code' })

    const hidden = screen.getByRole('checkbox', { name: 'Name2' })
    expect(hidden).not.toBeDisabled()

    await user.click(hidden)
    expect(props.onToggle).toHaveBeenCalledWith('name')
  })

  it('két látható oszlopnál egyik checkbox sem tiltott', () => {
    setup({ columns: COLUMNS_NO_LOCKED, isVisible: () => true })
    expect(screen.getByRole('checkbox', { name: 'Code' })).not.toBeDisabled()
    expect(screen.getByRole('checkbox', { name: 'Name2' })).not.toBeDisabled()
  })

  it('az utolsó látható oszlop sora tooltipet kap, de NEM kap is-locked stílust (húzható marad)', () => {
    setup({ columns: COLUMNS_NO_LOCKED, isVisible: (key) => key === 'code' })
    const row = screen.getByRole('checkbox', { name: 'Code' }).closest('.column-picker-row')
    expect(row).toHaveAttribute('title', 'columns.locked_tooltip')
    expect(row).not.toHaveClass('is-locked')
    expect(screen.getByRole('button', { name: 'columns.drag_handle Code' })).toBeInTheDocument()
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
