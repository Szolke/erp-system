import { useEffect, useRef } from 'react'
import { SlidersHorizontal } from 'lucide-react'
import { useTranslation } from '../../contexts/TranslationContext'
import { usePopoverDismiss } from '../../utils/usePopoverDismiss'

/**
 * "Szűrők" popover — a tab típusától függően más mezőket mutat:
 *  - invoices: Nyugták is / Fizetési státusz / Partner
 *  - aging: Partner
 * A gombon lévő badge az aktív (nem-alapértelmezett) mezők számát mutatja.
 * Minden változás azonnal alkalmazódik (nincs külön Alkalmaz/Mégse — a
 * checkbox/select önmagában is teljes, egylépéses állapotváltás, ellentétben
 * a dátum-tartomány kétlépéses rács-kijelölésével).
 *
 * A partnerlistát a szülő (ReportFilterCard) tölti be és adja át — így a
 * chip-sáv partner-NÉV feloldása ugyanazt a listát használja, nincs
 * duplikált lekérdezés.
 */
export default function FiltersPopover({ variant, filters, onChange, open, onOpenChange, id, partnerOptions, onNeedPartners }) {
  const { t } = useTranslation()
  const containerRef = useRef(null)
  const triggerRef = useRef(null)

  usePopoverDismiss(open, () => onOpenChange(false), containerRef, triggerRef)

  const showReceipts = variant === 'invoices'
  const showStatus = variant === 'invoices'
  const showPartner = variant === 'invoices' || variant === 'aging'

  useEffect(() => {
    if (!open || !showPartner) return
    onNeedPartners()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const activeCount =
    (showReceipts && filters.include_receipts ? 1 : 0) +
    (showStatus && filters.status ? 1 : 0) +
    (showPartner && filters.partner_id ? 1 : 0)

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
        <div className="fc-popover-panel fc-filters-panel" role="dialog" aria-label={t('reports.filters_button')}>
          {showReceipts && (
            <label className="fc-checkbox">
              <input
                type="checkbox"
                checked={filters.include_receipts}
                onChange={(e) => onChange({ include_receipts: e.target.checked })}
              />
              {t('reports.filter_include_receipts')}
            </label>
          )}
          {showStatus && (
            <label className="fc-popover-field">
              <span>{t('reports.filter_status')}</span>
              <select value={filters.status ?? ''} onChange={(e) => onChange({ status: e.target.value || null })}>
                <option value="">{t('common.all')}</option>
                <option value="open">{t('invoice.pay_open')}</option>
                <option value="partial">{t('invoice.pay_partial')}</option>
                <option value="paid">{t('invoice.pay_paid')}</option>
              </select>
            </label>
          )}
          {showPartner && (
            <label className="fc-popover-field">
              <span>{t('reports.filter_partner')}</span>
              <select
                value={filters.partner_id ?? ''}
                onChange={(e) => onChange({ partner_id: e.target.value || null })}
                disabled={partnerOptions === null}
              >
                <option value="">{t('common.all')}</option>
                {(partnerOptions ?? []).map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </label>
          )}
        </div>
      )}
    </div>
  )
}
