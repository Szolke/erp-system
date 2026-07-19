import { useRef } from 'react'
import { ChevronDown } from 'lucide-react'
import { useTranslation } from '../../contexts/TranslationContext'
import { usePopoverDismiss } from '../../utils/usePopoverDismiss'

/**
 * Dátum-alapja dropdown (nem toggle) — zárt állapotban a kiválasztott érték
 * látszik, nyitva mindkét opció alatt egy magyarázó sor. A korábbi magányos
 * "?" tooltip helyett a magyarázat ide, az opciók alá költözött.
 */
export default function DateBasisDropdown({ value, onChange, open, onOpenChange, id }) {
  const { t } = useTranslation()
  const containerRef = useRef(null)
  const triggerRef = useRef(null)

  usePopoverDismiss(open, () => onOpenChange(false), containerRef, triggerRef)

  const options = [
    { value: 'fulfillment', label: t('reports.date_basis_fulfillment'), hint: t('reports.date_basis_fulfillment_hint') },
    { value: 'issue', label: t('reports.date_basis_issue'), hint: t('reports.date_basis_issue_hint') },
  ]
  const current = options.find((o) => o.value === value) ?? options[0]

  function handleSelect(optValue) {
    onChange(optValue)
    onOpenChange(false)
    triggerRef.current?.focus()
  }

  return (
    <div className="fc-popover-root" ref={containerRef}>
      <button
        ref={triggerRef}
        type="button"
        className="fc-trigger-btn"
        onClick={() => onOpenChange(!open)}
        aria-haspopup="listbox"
        aria-expanded={open}
        id={id}
      >
        <span>{current.label}</span>
        <ChevronDown size={14} aria-hidden="true" />
      </button>

      {open && (
        <div className="fc-popover-panel report-datebasis-panel" role="listbox" aria-label={t('reports.filter_date_basis')}>
          {options.map((opt) => (
            <button
              key={opt.value}
              type="button"
              role="option"
              aria-selected={opt.value === value}
              className={'report-datebasis-option' + (opt.value === value ? ' is-selected' : '')}
              onClick={() => handleSelect(opt.value)}
            >
              <span className="report-datebasis-option-label">{opt.label}</span>
              <span className="report-datebasis-option-hint">{opt.hint}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
