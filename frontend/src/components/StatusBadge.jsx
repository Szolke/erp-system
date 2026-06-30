const PAYMENT_STATUS = { open: 'Nyitott', partial: 'Részben fizetve', paid: 'Kiegyenlített' }
const INVOICE_STATUS = { draft: 'Piszkozat', issued: 'Kiállítva', storno: 'Sztornó' }

export function PaymentStatusBadge({ status }) {
  return <span className={`badge badge-pay-${status}`}>{PAYMENT_STATUS[status] ?? status}</span>
}

export function InvoiceStatusBadge({ status }) {
  return <span className={`badge badge-inv-${status}`}>{INVOICE_STATUS[status] ?? status}</span>
}
