import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { invoices as invoiceApi } from '../../api/invoices'
import { useAuth } from '../../contexts/AuthContext'
import { PaymentStatusBadge, InvoiceStatusBadge } from '../../components/StatusBadge'

export default function InvoiceDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { can } = useAuth()
  const [invoice, setInvoice] = useState(null)
  const [payments, setPayments] = useState([])
  const [loading, setLoading] = useState(true)
  const [payForm, setPayForm] = useState({ payment_method_id: '', amount: '', paid_at: new Date().toISOString().split('T')[0] })
  const [payError, setPayError] = useState('')

  async function load() {
    const [invRes, payRes] = await Promise.all([invoiceApi.get(id), invoiceApi.payments(id)])
    setInvoice(invRes.data.data)
    setPayments(payRes.data.data ?? [])
    setLoading(false)
  }

  useEffect(() => { load() }, [id])

  async function handleCancel() {
    if (!confirm('Biztosan sztornózza a számlát?')) return
    await invoiceApi.cancel(id)
    navigate('/invoices')
  }

  async function handleAddPayment(e) {
    e.preventDefault()
    setPayError('')
    try {
      await invoiceApi.addPayment(id, payForm)
      await load()
      setPayForm({ payment_method_id: '', amount: '', paid_at: new Date().toISOString().split('T')[0] })
    } catch (err) {
      setPayError(err.response?.data?.message ?? 'Hiba')
    }
  }

  if (loading) return <p className="text-muted">Betöltés…</p>
  if (!invoice) return <p className="text-muted">Nem található.</p>

  const canCancel = can('invoice.cancel') && invoice.status === 'issued' && !invoice.storno_of_invoice_id
  const canPay = can('payment.create') && invoice.payment_status !== 'paid'

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">{invoice.invoice_number}</h1>
          <div className="flex mt-4">
            <InvoiceStatusBadge status={invoice.status} />
            <PaymentStatusBadge status={invoice.payment_status} />
          </div>
        </div>
        <div className="flex">
          {canCancel && (
            <button className="btn btn-danger" onClick={handleCancel}>Sztornó</button>
          )}
          <Link to="/invoices" className="btn btn-secondary">← Vissza</Link>
        </div>
      </div>

      <div className="card">
        <div className="detail-grid">
          <div className="detail-row"><span className="detail-label">Partner</span><span className="detail-value">{invoice.partner?.name}</span></div>
          <div className="detail-row"><span className="detail-label">Kiállítás</span><span className="detail-value">{invoice.issue_date}</span></div>
          <div className="detail-row"><span className="detail-label">Teljesítés</span><span className="detail-value">{invoice.fulfillment_date}</span></div>
          <div className="detail-row"><span className="detail-label">Fizetési határidő</span><span className="detail-value">{invoice.due_date}</span></div>
          <div className="detail-row"><span className="detail-label">Deviza / árfolyam</span><span className="detail-value">{invoice.currency} ({invoice.exchange_rate})</span></div>
          <div className="detail-row"><span className="detail-label">Fizetési mód</span><span className="detail-value">{invoice.payment_method?.name}</span></div>
        </div>
        <table className="items-table">
          <thead><tr><th>Megnevezés</th><th>Me.</th><th>Mennyiség</th><th>Egységár</th><th>ÁFA</th><th>Nettó</th><th>Bruttó</th></tr></thead>
          <tbody>
            {invoice.items?.map((item) => (
              <tr key={item.id}>
                <td>{item.description}</td>
                <td>{item.unit}</td>
                <td className="text-right">{item.quantity}</td>
                <td className="text-right">{Number(item.unit_price).toLocaleString('hu')}</td>
                <td>{item.vat_rate?.name}</td>
                <td className="text-right">{Number(item.net_amount).toLocaleString('hu')}</td>
                <td className="text-right">{Number(item.gross_amount).toLocaleString('hu')}</td>
              </tr>
            ))}
            <tr className="total-row">
              <td colSpan={5}>Összesen</td>
              <td className="text-right">{Number(invoice.net_total).toLocaleString('hu')}</td>
              <td className="text-right">{Number(invoice.gross_total).toLocaleString('hu')} {invoice.currency}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="card">
        <strong>Befizetések</strong>
        {payments.length === 0 && <p className="text-muted mt-4">Még nincs befizetés.</p>}
        {payments.length > 0 && (
          <table className="mt-4">
            <thead><tr><th>Dátum</th><th>Mód</th><th>Összeg</th><th>Hivatkozás</th></tr></thead>
            <tbody>
              {payments.map((p) => (
                <tr key={p.id}>
                  <td>{p.paid_at?.split('T')[0]}</td>
                  <td>{p.payment_method?.name}</td>
                  <td className="text-right">{Number(p.amount).toLocaleString('hu')} {p.currency}</td>
                  <td>{p.reference ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {canPay && (
          <form onSubmit={handleAddPayment} style={{ display: 'flex', gap: 10, marginTop: 16, alignItems: 'flex-end' }}>
            <div className="form-group" style={{ margin: 0 }}>
              <label>Összeg</label>
              <input type="number" step="0.01" value={payForm.amount} onChange={(e) => setPayForm({ ...payForm, amount: e.target.value })} required style={{ width: 120 }} />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>Mód (ID)</label>
              <input type="number" value={payForm.payment_method_id} onChange={(e) => setPayForm({ ...payForm, payment_method_id: e.target.value })} required style={{ width: 60 }} placeholder="1" />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>Dátum</label>
              <input type="date" value={payForm.paid_at} onChange={(e) => setPayForm({ ...payForm, paid_at: e.target.value })} required style={{ width: 140 }} />
            </div>
            <button className="btn btn-primary" type="submit">Rögzítés</button>
            {payError && <span className="form-error">{payError}</span>}
          </form>
        )}
      </div>
    </div>
  )
}
