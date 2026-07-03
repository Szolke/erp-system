import { useEffect, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { receipts } from '../../api/receipts'
import client from '../../api/client'
import { useTranslation } from '../../contexts/TranslationContext'

const today = () => new Date().toISOString().split('T')[0]
const emptyItem = () => ({ description: '', quantity: 1, unit_price: '', vat_rate_id: '' })

export default function ReceiptCreatePage() {
  const navigate = useNavigate()
  const { t } = useTranslation()
  const [vatRates, setVatRates] = useState([])
  const [payMethods, setPayMethods] = useState([])
  const [form, setForm] = useState({ payment_method_id: '', issue_date: today(), currency: 'HUF' })
  const [items, setItems] = useState([emptyItem()])
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [showModal, setShowModal] = useState(false)
  const [issuedResult, setIssuedResult] = useState(null) // {id, number} after successful POST

  useEffect(() => {
    Promise.all([client.get('/api/vat-rates'), client.get('/api/payment-methods')]).then(([v, m]) => {
      setVatRates(v.data.data ?? [])
      setPayMethods(m.data.data ?? [])
      if (v.data.data?.[0]) setItems([{ ...emptyItem(), vat_rate_id: v.data.data[0].id }])
      if (m.data.data?.[0]) setForm((f) => ({ ...f, payment_method_id: m.data.data[0].id }))
    })
  }, [])

  function setItem(i, k, v) { setItems((it) => it.map((item, idx) => idx === i ? { ...item, [k]: v } : item)) }

  function lineGross(item) {
    const qty = Number(item.quantity) || 0
    const price = Number(item.unit_price) || 0
    const vat = vatRates.find((v) => v.id == item.vat_rate_id)
    const vatPct = vat ? Number(vat.rate_percent) : 0
    return qty * price * (1 + vatPct / 100)
  }

  // Validates and opens the confirmation modal — no POST here
  function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setIssuedResult(null)
    setShowModal(true)
  }

  // Called from modal confirm button — does the actual POST
  async function handleConfirm() {
    setSaving(true)
    setError('')
    try {
      const payload = {
        ...form,
        items: items.map((i) => ({
          description: i.description,
          quantity: Number(i.quantity),
          unit_price: Number(i.unit_price),
          vat_rate_id: Number(i.vat_rate_id),
        })),
      }
      const res = await receipts.create(payload)
      const rec = res.data.data
      setIssuedResult({ id: rec.id, number: rec.receipt_number })
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? t('common.error'))
    } finally {
      setSaving(false)
    }
  }

  // Derived values for modal summary
  const payMethodName = payMethods.find((m) => m.id == form.payment_method_id)?.name ?? '—'
  const grandTotal = items.reduce((sum, item) => sum + lineGross(item), 0)

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('receipt.new_title')}</h1>
        <Link to="/receipts" className="btn btn-secondary">{t('common.back')}</Link>
      </div>
      {error && !showModal && <div className="alert-error mb-4">{error}</div>}
      <form onSubmit={handleSubmit}>
        <div className="card">
          <div style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 16 }}>
            <div className="form-group">
              <label>{t('invoice.pay_method')}</label>
              <select value={form.payment_method_id} onChange={(e) => setForm({ ...form, payment_method_id: e.target.value })} required>
                {payMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label>{t('receipt.date')}</label>
              <input type="date" value={form.issue_date} onChange={(e) => setForm({ ...form, issue_date: e.target.value })} required />
            </div>
          </div>
        </div>
        <div className="card">
          <table className="items-table">
            <thead>
              <tr>
                <th>{t('invoice.description')}</th>
                <th>{t('invoice.quantity')}</th>
                <th>{t('invoice.unit_price')}</th>
                <th>{t('invoice.vat')}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i}>
                  <td><input value={item.description} onChange={(e) => setItem(i, 'description', e.target.value)} required /></td>
                  <td><input type="number" step="0.001" value={item.quantity} onChange={(e) => setItem(i, 'quantity', e.target.value)} style={{ width: 80 }} /></td>
                  <td><input type="number" step="0.01" value={item.unit_price} onChange={(e) => setItem(i, 'unit_price', e.target.value)} style={{ width: 100 }} required /></td>
                  <td>
                    <select value={item.vat_rate_id} onChange={(e) => setItem(i, 'vat_rate_id', e.target.value)} required style={{ width: 120 }}>
                      {vatRates.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                    </select>
                  </td>
                  <td>{items.length > 1 && <button type="button" className="btn btn-danger btn-sm" onClick={() => setItems((it) => it.filter((_, idx) => idx !== i))}>×</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <button type="button" className="btn btn-secondary btn-sm mt-4" onClick={() => setItems((it) => [...it, { ...emptyItem(), vat_rate_id: vatRates[0]?.id ?? '' }])}>{t('invoice.add_item')}</button>
        </div>
        <div className="flex">
          <button className="btn btn-primary" type="submit">{t('receipt.submit')}</button>
          <Link to="/receipts" className="btn btn-secondary">{t('common.cancel')}</Link>
        </div>
      </form>

      {showModal && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)',
          zIndex: 1000, display: 'flex', alignItems: 'flex-start',
          justifyContent: 'center', overflowY: 'auto', padding: '40px 16px',
        }}>
          <div className="card" style={{ maxWidth: 680, width: '100%' }}>
            {issuedResult ? (
              // Success state — receipt number from server response
              <>
                <div className="alert-success mb-4" style={{ fontSize: 15 }}>
                  {t('receipt.issued_ok', { number: issuedResult.number })}
                </div>
                <div className="flex">
                  <button className="btn btn-primary" onClick={() => navigate(`/receipts/${issuedResult.id}`)}>
                    {t('receipt.view_detail')}
                  </button>
                  <Link to="/documents" className="btn btn-secondary">{t('common.back_to_list')}</Link>
                </div>
              </>
            ) : (
              // Confirmation state — summary without receipt number
              <>
                <h2 style={{ marginTop: 0, marginBottom: 16 }}>{t('receipt.confirm_title')}</h2>
                {error && <div className="alert-error mb-4">{error}</div>}

                <table style={{ width: '100%', marginBottom: 16, borderCollapse: 'collapse' }}>
                  <tbody>
                    {[
                      [t('invoice.pay_method'), payMethodName],
                      [t('receipt.date'), form.issue_date],
                      [t('common.currency'), form.currency],
                    ].map(([label, value]) => (
                      <tr key={label}>
                        <td style={{ color: 'var(--color-muted)', paddingBottom: 6, width: '40%', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.04em' }}>{label}</td>
                        <td style={{ paddingBottom: 6 }}>{value}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>

                <strong>{t('invoice.items')}</strong>
                <table className="items-table" style={{ marginTop: 8, marginBottom: 16 }}>
                  <thead>
                    <tr>
                      <th style={{ textAlign: 'left' }}>{t('invoice.description')}</th>
                      <th>{t('invoice.quantity')}</th>
                      <th>{t('invoice.unit_price')}</th>
                      <th>{t('invoice.vat')}</th>
                      <th>{t('invoice.line_total')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item, i) => {
                      const vat = vatRates.find((v) => v.id == item.vat_rate_id)
                      return (
                        <tr key={i}>
                          <td>{item.description || '—'}</td>
                          <td>{item.quantity}</td>
                          <td>{Number(item.unit_price).toLocaleString('hu-HU')}</td>
                          <td>{vat?.name ?? '—'}</td>
                          <td>{lineGross(item).toLocaleString('hu-HU', { maximumFractionDigits: 0 })}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                  <tfoot>
                    <tr style={{ fontWeight: 600, borderTop: `2px solid var(--color-border)` }}>
                      <td colSpan={4} style={{ paddingTop: 8 }}>{t('common.total')}</td>
                      <td style={{ paddingTop: 8 }}>
                        {grandTotal.toLocaleString('hu-HU', { maximumFractionDigits: 0 })} {form.currency}
                      </td>
                    </tr>
                  </tfoot>
                </table>

                <div className="flex">
                  <button className="btn btn-primary" onClick={handleConfirm} disabled={saving}>
                    {saving ? t('common.saving') : t('invoice.confirm_submit')}
                  </button>
                  <button
                    className="btn btn-secondary"
                    onClick={() => { setShowModal(false); setError('') }}
                    disabled={saving}
                  >
                    {t('common.cancel')}
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
