import { useRef } from 'react'
import { Columns3, GripVertical } from 'lucide-react'
import {
  DndContext, closestCenter, PointerSensor, KeyboardSensor, useSensor, useSensors,
} from '@dnd-kit/core'
import {
  SortableContext, verticalListSortingStrategy, sortableKeyboardCoordinates, useSortable,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { restrictToVerticalAxis, restrictToParentElement } from '@dnd-kit/modifiers'
import { useTranslation } from '../contexts/TranslationContext'
import { usePopoverDismiss } from '../utils/usePopoverDismiss'
import { MIN_VISIBLE_COLUMNS } from '../hooks/useListColumns'

/**
 * Listafüggetlen oszlopválasztó popover — a useListColumns hookkal együtt
 * bármely listanézetben újrahasználható. Nincs "Mentés" gomb: minden
 * kattintás / húzás azonnal alkalmazódik (a hook menti debounce-olva a
 * háttérben).
 *
 * Kontrollált nyitva-tartás (open/onOpenChange), a projekt többi popoverjének
 * mintáját követve (DocumentFiltersPopover, DateRangePicker) — így egy közös
 * `openPopover` state-tel koordinálható a szülőben, ha több popover van egy
 * fejlécsorban (csak egy legyen nyitva egyszerre).
 *
 * A panel alapból BALRA van horgonyozva (a trigger bal széléhez, jobbra
 * nyílik) — NEM jobbra (fc-popover-panel--right), mert a trigger gomb szinte
 * mindig egy bal oldali eszköztár-sáv eleje/egyetlen tagja (pl. Cégek,
 * Munkakörök, Eszköztípusok oldal — ott `.search-row`-t használnak, fix
 * `max-width: 300px` keresővel), ahol a jobbra-horgonyzás a sidebar ALÁ
 * lógatná a panelt (balra nyílna egy, a lapszél közelében álló gombtól) — l.
 * docs/progress.md a konkrét hibajelenség leírásáért.
 *
 * A `align="right"` prop (a `DateRangePicker` mintáját követve) ETTŐL
 * ELTÉRŐ esetre való: ahol a trigger elé rugalmasan táguló elem kerül (pl. a
 * Bizonylatok oldal `.doc-search-wrap`-je `flex: 1`), így a gomb a sáv JOBB
 * szélére kerülhet — ott a bal-horgonyzás lógna le a képernyő szélén túl.
 *
 * Minimum-oszlop guard: ha már csak egyetlen oszlop látszik, annak a checkboxa
 * letiltódik (a küszöböt a hook `MIN_VISIBLE_COLUMNS`-a adja) — a lista nem
 * üresíthető ki. A húzás ettől függetlenül marad engedélyezett.
 *
 * Sorrendezés: a `locked` sorok fogantyú NÉLKÜL renderelődnek és NEM részei a
 * sortable halmaznak (a hook úgyis fail-safe kényszeríti vissza a locked
 * pozíciókat, ha valahogy mégis bekerülnének) — a húzás CSAK a fogantyúról
 * indulhat, a checkbox-kattintás (külön elem, a `column-picker-row-label`
 * alatt) ettől független marad.
 */
export default function ColumnPicker({ columns, isVisible, onToggle, onReorder, onReset, isDirty, open, onOpenChange, id, align = 'left' }) {
  const { t } = useTranslation()
  const containerRef = useRef(null)
  const triggerRef = useRef(null)

  usePopoverDismiss(open, () => onOpenChange(false), containerRef, triggerRef)

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

  const visibleCount = columns.filter((col) => isVisible(col.key)).length
  const sortableIds = columns.filter((col) => !col.locked).map((col) => col.key)

  // Minimum-oszlop guard (a küszöböt a hook definiálja): ha már csak ennyi
  // oszlop látszik, a MÉG LÁTHATÓ oszlopok checkboxa letiltódik, hogy a lista
  // ne üresedhessen ki. A hook a `toggle`-t magát is védi — itt csak azt
  // előzzük meg, hogy a felhasználó egy néma no-opra kattintson.
  const atMinimum = visibleCount <= MIN_VISIBLE_COLUMNS

  function handleDragEnd(event) {
    const { active, over } = event
    if (!over || active.id === over.id) return
    onReorder(active.id, over.id)
  }

  return (
    <div className="fc-popover-root" ref={containerRef}>
      <button
        ref={triggerRef}
        type="button"
        className="fc-trigger-btn"
        onClick={() => onOpenChange(!open)}
        aria-haspopup="dialog"
        aria-expanded={open}
        id={id}
      >
        <Columns3 size={14} aria-hidden="true" />
        <span>{t('columns.picker_button')}</span>
      </button>

      {open && (
        <div className={'fc-popover-panel column-picker-panel' + (align === 'right' ? ' fc-popover-panel--right' : '')} role="dialog" aria-label={t('columns.picker_button')}>
          <p className="column-picker-count">{t('columns.visible_count', { visible: visibleCount, total: columns.length })}</p>

          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            modifiers={[restrictToVerticalAxis, restrictToParentElement]}
            onDragEnd={handleDragEnd}
          >
            <SortableContext items={sortableIds} strategy={verticalListSortingStrategy}>
              <div className="column-picker-list">
                {columns.map((col) => (
                  <ColumnPickerRow
                    key={col.key}
                    column={col}
                    checked={isVisible(col.key)}
                    lastVisible={atMinimum && isVisible(col.key)}
                    onToggle={() => onToggle(col.key)}
                  />
                ))}
              </div>
            </SortableContext>
          </DndContext>

          <div className="fc-popover-footer">
            <button type="button" className="fc-clear-all" onClick={onReset} disabled={!isDirty}>
              {t('columns.reset')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

function ColumnPickerRow({ column, checked, lastVisible, onToggle }) {
  const { t } = useTranslation()
  const label = t(column.label)
  // Két, egymástól független ok tilthatja a kikapcsolást: az oszlop `locked`
  // (soha nem rejthető), vagy ez az utolsó megmaradt látható oszlop. A `locked`
  // sorral ellentétben az utolsó látható oszlop TOVÁBBRA IS húzható (a
  // sorrendezését semmi nem indokolja korlátozni), ezért csak a checkbox
  // tiltódik, a sor `is-locked` stílust nem kap.
  //
  // A tooltip mindkét esetben a meglévő `columns.locked_tooltip` ("Ez az oszlop
  // nem rejthető el") — tényszerűen igaz az utolsó látható oszlopra is. Külön,
  // az OKOT is magyarázó szöveghez új fordítási kulcs kellene a
  // TranslationSeederben (backend), ez a kör szándékosan frontend-only.
  const hideDisabled = column.locked || lastVisible
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: column.key,
    disabled: column.locked,
  })

  const style = column.locked ? undefined : {
    transform: CSS.Transform.toString(transform),
    transition,
  }

  return (
    <div
      ref={column.locked ? undefined : setNodeRef}
      style={style}
      className={'column-picker-row' + (column.locked ? ' is-locked' : '') + (isDragging ? ' is-dragging' : '')}
      title={hideDisabled ? t('columns.locked_tooltip') : undefined}
    >
      {!column.locked && (
        <button
          type="button"
          className="column-picker-handle"
          aria-label={t('columns.drag_handle', { name: label })}
          {...attributes}
          {...listeners}
        >
          <GripVertical size={14} aria-hidden="true" />
        </button>
      )}
      <label className="column-picker-row-label">
        <span>{label}</span>
        <input
          type="checkbox"
          checked={checked}
          disabled={hideDisabled}
          onChange={onToggle}
        />
      </label>
    </div>
  )
}
