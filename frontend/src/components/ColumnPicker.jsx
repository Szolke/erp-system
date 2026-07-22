import { useRef } from 'react'
import { Columns3 } from 'lucide-react'
import { useTranslation } from '../contexts/TranslationContext'
import { usePopoverDismiss } from '../utils/usePopoverDismiss'

/**
 * Listafüggetlen oszlopválasztó popover — a useListColumns hookkal együtt
 * bármely listanézetben újrahasználható. Nincs "Mentés" gomb: minden
 * kattintás azonnal alkalmazódik (a hook menti debounce-olva a háttérben).
 *
 * Kontrollált nyitva-tartás (open/onOpenChange), a projekt többi popoverjének
 * mintáját követve (DocumentFiltersPopover, DateRangePicker) — így egy közös
 * `openPopover` state-tel koordinálható a szülőben, ha több popover van egy
 * fejlécsorban (csak egy legyen nyitva egyszerre).
 *
 * A panel BALRA van horgonyozva (a trigger bal széléhez, jobbra nyílik) —
 * NEM jobbra (fc-popover-panel--right), mert a trigger gomb szinte mindig egy
 * bal oldali eszköztár-sáv eleje/egyetlen tagja (pl. Cégek, Munkakörök,
 * Eszköztípusok oldal), ahol a jobbra-horgonyzás a sidebar ALÁ lógatná a
 * panelt (balra nyílna egy, a lapszél közelében álló gombtól) — l.
 * docs/progress.md a konkrét hibajelenség leírásáért.
 */
export default function ColumnPicker({ columns, isVisible, onToggle, onReset, isDirty, open, onOpenChange, id }) {
  const { t } = useTranslation()
  const containerRef = useRef(null)
  const triggerRef = useRef(null)

  usePopoverDismiss(open, () => onOpenChange(false), containerRef, triggerRef)

  const visibleCount = columns.filter((col) => isVisible(col.key)).length

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
        <div className="fc-popover-panel column-picker-panel" role="dialog" aria-label={t('columns.picker_button')}>
          <p className="column-picker-count">{t('columns.visible_count', { visible: visibleCount, total: columns.length })}</p>

          <div className="column-picker-list">
            {columns.map((col) => (
              <label
                key={col.key}
                className={'column-picker-row' + (col.locked ? ' is-locked' : '')}
                title={col.locked ? t('columns.locked_tooltip') : undefined}
              >
                <span>{t(col.label)}</span>
                <input
                  type="checkbox"
                  checked={isVisible(col.key)}
                  disabled={col.locked}
                  onChange={() => onToggle(col.key)}
                />
              </label>
            ))}
          </div>

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
