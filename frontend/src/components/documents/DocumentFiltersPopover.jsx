import { useRef } from 'react'
import { SlidersHorizontal } from 'lucide-react'
import { useTranslation } from '../../contexts/TranslationContext'
import { usePopoverDismiss } from '../../utils/usePopoverDismiss'
import { statusLabel } from '../../utils/documentStatus'

const STATUS_VALUES = ['paid', 'overdue', 'partially_paid', 'issued', 'draft', 'storno']

/**
 * "Szűrők" popover a Bizonylatok oldalon: bizonylat-állapot (a backend
 * display_status-a, benne a "Lejárt" opcióval) + deviza — ezek ritkábban
 * használt mezők, ezért kerültek ki a fő szűrősorból a popoverbe.
 */
export default function DocumentFiltersPopover({ filters, onChange, open, onOpenChange, id }) {
  const { t } = useTranslation()
  const containerRef = useRef(null)
  const triggerRef = useRef(null)

  usePopoverDismiss(open, () => onOpenChange(false), containerRef, triggerRef)

  const activeCount = (filters.status ? 1 : 0) + (filters.currency ? 1 : 0)

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
        <SlidersHorizontal size={14} aria-hidden="true" />
        <span>{t('reports.filters_button')}</span>
        {activeCount > 0 && <span className="fc-badge">{activeCount}</span>}
      </button>

      {open && (
        <div className="fc-popover-panel fc-filters-panel fc-popover-panel--right" role="dialog" aria-label={t('reports.filters_button')}>
          <label className="fc-popover-field">
            <span>{t('document.filter_status')}</span>
            <select value={filters.status ?? ''} onChange={(e) => onChange({ status: e.target.value || null })}>
              <option value="">{t('common.all')}</option>
              {STATUS_VALUES.map((value) => (
                <option key={value} value={value}>{statusLabel(t, value)}</option>
              ))}
            </select>
          </label>
          <label className="fc-popover-field">
            <span>{t('common.currency')}</span>
            <select value={filters.currency ?? ''} onChange={(e) => onChange({ currency: e.target.value || null })}>
              <option value="">{t('document.curr_all')}</option>
              <option value="HUF">HUF</option>
              <option value="EUR">EUR</option>
              <option value="USD">USD</option>
            </select>
          </label>
        </div>
      )}
    </div>
  )
}
