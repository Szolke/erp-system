import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { invoices as invoiceApi } from '../../api/invoices'
import client from '../../api/client'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { PaymentStatusBadge, InvoiceStatusBadge } from '../../components/StatusBadge'

const todayStr = () => new Date().toISOString().split('T')[0]

export default function InvoiceDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { can } = useAuth()
  const { t } = useTranslation()
  const [invoice, setInvoice] = useState(null)
  const [payments, setPayments] = useState([])
  const [payMethods, setPayMethods] = useState([])
  const [loading, setLoading] = useState(true)
  const [payForm, setPayForm] = useState({ payment_method_id: '', amount: '', paid_at: todayStr() })
  const [payError, setPayError] = useState('')
  const [refunding, setRefunding] = useState(false)
  const [refundMsg, setRefundMsg] = useState('')

  async function load() {
    const [invRes, payRes, pmRes] = await Promise.all([
      invoiceApi.get(id),
      invoiceApi.payments(id),
      client.get('/api/payment-methods'),
    ])
    setInvoice(invRes.data.data)
    setPayments(payRes.data.data ?? [])
    const methods = pmRes.data.data ?? []
    setPayMethods(methods)
    if (methods.length) setPayForm((f) => (f.payment_method_id ? f : { ...f, payment_method_id: methods[0].id }))
    setLoading(false)
  }

  useEffect(() => { load() }, [id])

  async function handleCancel() {
    if (!confirm(t('invoice.storno_confirm'))) return
    await invoiceApi.cancel(id)
    navigate('/invoices')
  }

  async function handleRefund() {
    if (!confirm(t('simplepay.refund_confirm'))) return
    setRefunding(true)
    setRefundMsg('')
    try {
      await client.post(`/api/invoices/${id}/simplepay-refund`)
      setRefundMsg(t('simplepay.refund_ok'))
      await load()
    } catch (err) {
      setRefundMsg(err.response?.data?.message ?? t('simplepay.refund_err'))
    } finally {
      setRefunding(false)
    }
  }

  async function downloadPdf() {
    const res = await client.get(`/api/invoices/${invoice.id}/pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `${invoice.invoice_number}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  }

  async function handleAddPayment(e) {
    e.preventDefault()
    setPayError('')
    try {
      await invoiceApi.addPayment(id, payForm)
      await load()
      setPayForm((f) => ({ payment_method_id: f.payment_method_id, amount: '', paid_at: todayStr() }))
    } catch (err) {
      setPayError(err.response?.data?.message ?? 'Hiba')
    }
  }

  if (loading) return <p className="text-muted">{t('common.loading')}</p>
  if (!invoice) return <p className="text-muted">{t('common.not_found')}</p>

  const canCancel = can('invoice.cancel') && invoice.status === 'issued' && !invoice.storno_of_invoice_id
  const canPay = can('payment.create') && invoice.payment_status !== 'paid'
  const canRefund = can('invoice.cancel') && invoice.status === 'issued' && invoice.simplepay_transaction?.status === 'success'

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
          {canRefund && (
            <button className="btn btn-danger" onClick={handleRefund} disabled={refunding}>
              {refunding ? '…' : t('simplepay.refund')}
            </button>
          )}
          {canCancel && !canRefund && (
            <button className="btn btn-danger" onClick={handleCancel}>{t('common.storno')}</button>
          )}
          <button className="btn btn-secondary" onClick={downloadPdf}>PDF</button>
          <Link to="/documents" className="btn btn-secondary">{t('common.back')}</Link>
        </div>
      </div>

      {refundMsg && (
        <div className={refundMsg === t('simplepay.refund_ok') ? 'alert-success mb-4' : 'alert-error mb-4'}>
          {refundMsg}
        </div>
      )}

      <div className="card">
        <div className="detail-grid">
          <div className="detail-row"><span className="detail-label">{t('invoice.partner_col')}</span><span className="detail-value">{invoice.partner?.name}</span></div>
          <div className="detail-row"><span className="detail-label">{t('invoice.issue_date')}</span><span className="detail-value">{invoice.issue_date}</span></div>
          <div className="detail-row"><span className="detail-label">{t('invoice.fulfillment')}</span><span className="detail-value">{invoice.fulfillment_date}</span></div>
          <div className="detail-row"><span className="detail-label">{t('invoice.due_date')}</span><span className="detail-value">{invoice.due_date}</span></div>
          <div className="detail-row"><span className="detail-label">{t('invoice.currency_rate')}</span><span className="detail-value">{invoice.currency} ({invoice.exchange_rate})</span></div>
          <div className="detail-row"><span className="detail-label">{t('invoice.pay_method')}</span><span className="detail-value">{invoice.payment_method?.name}</span></div>
        </div>
        <table className="items-table">
          <thead><tr><th>{t('invoice.description')}</th><th>{t('invoice.unit')}</th><th>{t('invoice.quantity')}</th><th>{t('invoice.unit_price')}</th><th>{t('invoice.vat')}</th><th>{t('invoice.net')}</th><th>{t('invoice.gross')}</th></tr></thead>
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
              <td colSpan={5}>{t('common.total')}</td>
              <td className="text-right">{Number(invoice.net_total).toLocaleString('hu')}</td>
              <td className="text-right">{Number(invoice.gross_total).toLocaleString('hu')} {invoice.currency}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="card">
        <strong>{t('invoice.payments_title')}</strong>
        {payments.length === 0 && <p className="text-muted mt-4">{t('invoice.no_payments')}</p>}
        {payments.length > 0 && (
          <table className="mt-4">
            <thead><tr><th>{t('common.date')}</th><th>Mód</th><th>{t('common.amount')}</th><th>{t('invoice.reference')}</th></tr></thead>
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
          <form onSubmit={handleAddPayment} style={{ display: 'flex', gap: 10, marginTop: 16, alignItems: 'flex-end', flexWrap: 'wrap' }}>
            <div className="form-group" style={{ margin: 0 }}>
              <label>{t('common.amount')}</label>
              <input type="number" step="0.01" value={payForm.amount} onChange={(e) => setPayForm({ ...payForm, amount: e.target.value })} required style={{ width: 130 }} />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>{t('invoice.pay_method')}</label>
              <select value={payForm.payment_method_id} onChange={(e) => setPayForm({ ...payForm, payment_method_id: e.target.value })} required style={{ width: 160 }}>
                {payMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>{t('common.date')}</label>
              <input type="date" value={payForm.paid_at} onChange={(e) => setPayForm({ ...payForm, paid_at: e.target.value })} required style={{ width: 170 }} />
            </div>
            <button className="btn btn-primary" type="submit">{t('invoice.add_payment')}</button>
            {payError && <span className="form-error">{payError}</span>}
          </form>
        )}
      </div>
    </div>
  )
}
