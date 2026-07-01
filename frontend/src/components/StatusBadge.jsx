const PAYMENT_STATUS = { open: 'Nyitott', partial: 'Részben fizetve', paid: 'Kiegyenlített' }
const INVOICE_STATUS = { draft: 'Piszkozat', issued: 'Kiállítva', storno: 'Sztornó' }
const DOC_TYPE_LABEL = { invoice: 'Számla', invoice_storno: 'Sztornó számla', receipt: 'Nyugta', receipt_storno: 'Sztornó nyugta' }

export function PaymentStatusBadge({ status }) {
  return <span className={`badge badge-pay-${status}`}>{PAYMENT_STATUS[status] ?? status}</span>
}

export function InvoiceStatusBadge({ status }) {
  return <span className={`badge badge-inv-${status}`}>{INVOICE_STATUS[status] ?? status}</span>
}

export function DocumentTypeBadge({ type }) {
  const cssClass = type?.replace('_', '-')
  return <span className={`badge badge-type-${cssClass}`}>{DOC_TYPE_LABEL[type] ?? type}</span>
}
