import { useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Plus, ChevronDown } from 'lucide-react'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { usePopoverDismiss } from '../../utils/usePopoverDismiss'

/**
 * Egyetlen elsődleges (accent) gomb két korábbi, azonos súlyú kék gomb
 * helyett. Ha a usernek csak az egyik jogosultsága van meg, a gomb
 * legördülő NÉLKÜL, közvetlenül azt az űrlapot indítja.
 */
export default function NewDocumentButton() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const containerRef = useRef(null)
  const triggerRef = useRef(null)

  usePopoverDismiss(open, () => setOpen(false), containerRef, triggerRef)

  const canInvoice = can('invoice.create')
  const canReceipt = can('receipt.create')

  if (!canInvoice && !canReceipt) return null

  if (canInvoice && !canReceipt) {
    return (
      <button type="button" className="btn btn-primary" onClick={() => navigate('/invoices/new')}>
        <Plus size={14} aria-hidden="true" />
        {t('document.new_invoice_action')}
      </button>
    )
  }
  if (canReceipt && !canInvoice) {
    return (
      <button type="button" className="btn btn-primary" onClick={() => navigate('/receipts/new')}>
        <Plus size={14} aria-hidden="true" />
        {t('document.new_receipt_action')}
      </button>
    )
  }

  return (
    <div className="fc-popover-root" ref={containerRef}>
      <button
        ref={triggerRef}
        type="button"
        className="btn btn-primary"
        onClick={() => setOpen(!open)}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <Plus size={14} aria-hidden="true" />
        {t('document.new_document')}
        <ChevronDown size={14} aria-hidden="true" />
      </button>

      {open && (
        <div className="fc-popover-panel fc-menu-panel" role="menu">
          <button
            type="button" role="menuitem" className="fc-menu-item"
            onClick={() => { setOpen(false); navigate('/invoices/new') }}
          >
            {t('document.new_invoice_action')}
          </button>
          <button
            type="button" role="menuitem" className="fc-menu-item"
            onClick={() => { setOpen(false); navigate('/receipts/new') }}
          >
            {t('document.new_receipt_action')}
          </button>
        </div>
      )}
    </div>
  )
}
