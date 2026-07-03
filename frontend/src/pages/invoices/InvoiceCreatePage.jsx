import { useEffect, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { invoices } from '../../api/invoices'
import { partners } from '../../api/partners'
import client from '../../api/client'
import ProductComboBox from '../../components/ProductComboBox'
import { useTranslation } from '../../contexts/TranslationContext'

const today = () => new Date().toISOString().split('T')[0]
const plus30 = () => { const d = new Date(); d.setDate(d.getDate() + 30); return d.toISOString().split('T')[0] }

const emptyItem = (defaultVatId = '') => ({
  product_id: null,
  description: '',
  quantity: 1,
  unit: 'db',
  unit_price: '',
  vat_rate_id: defaultVatId,
  discount_percent: '',
})

export default function InvoiceCreatePage() {
  const navigate = useNavigate()
  const { t } = useTranslation()
  const [partnerList, setPartnerList] = useState([])
  const [vatRates, setVatRates] = useState([])
  const [payMethods, setPayMethods] = useState([])
  const [defaultVatId, setDefaultVatId] = useState('')
  const [form, setForm] = useState({
    partner_id: '', payment_method_id: '',
    issue_date: today(), fulfillment_date: today(), due_date: plus30(),
    currency: 'HUF', notes: '',
  })
  const [items, setItems] = useState([emptyItem()])
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [showModal, setShowModal] = useState(false)
  const [issuedResult, setIssuedResult] = useState(null) // {id, number} after successful POST

  useEffect(() => {
    Promise.all([
      partners.list(),
      client.get('/api/vat-rates'),
      client.get('/api/payment-methods'),
    ]).then(([p, v, m]) => {
      setPartnerList(p.data.data ?? [])
      setVatRates(v.data.data ?? [])
      setPayMethods(m.data.data ?? [])
      const firstVat = v.data.data?.[0]?.id ?? ''
      setDefaultVatId(firstVat)
      setItems([emptyItem(firstVat)])
      if (m.data.data?.[0]) setForm((f) => ({ ...f, payment_method_id: m.data.data[0].id }))
    })
  }, [])

  function setField(k, v) { setForm((f) => ({ ...f, [k]: v })) }

  function setItem(i, k, v) {
    setItems((it) => it.map((item, idx) => idx === i ? { ...item, [k]: v } : item))
  }

  function handleProductSelect(i, product) {
    setItems((it) => it.map((item, idx) => idx === i ? {
      ...item,
      product_id: product.id,
      _product: product,           // csak megjelenítéshez (nem megy az API-ba)
      description: product.name,
      unit: product.unit,
      unit_price: product.base_price,
      vat_rate_id: product.vat_rate_id,
    } : item))
  }

  function handleProductClear(i) {
    setItems((it) => it.map((item, idx) => idx === i ? {
      ...item,
      product_id: null,
      _product: null,
      description: '',
      unit: 'db',
      unit_price: '',
      vat_rate_id: defaultVatId,
    } : item))
  }

  function addItem() {
    setItems((it) => [...it, emptyItem(defaultVatId)])
  }

  function removeItem(i) {
    setItems((it) => it.filter((_, idx) => idx !== i))
  }

  function lineGross(item) {
    const qty = Number(item.quantity) || 0
    const price = Number(item.unit_price) || 0
    const disc = Number(item.discount_percent) || 0
    const vat = vatRates.find((v) => v.id == item.vat_rate_id)
    const vatPct = vat ? Number(vat.rate_percent) : 0
    return qty * price * (1 - disc / 100) * (1 + vatPct / 100)
  }

  // Validates and opens the confirmation modal — no POST here
  function handleSubmit(e) {
    e.preventDefault()
    setError('')
    if (items.some((item) => !item.product_id)) {
      setError(t('invoice.product_required'))
      return
    }
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
        items: items.map((item) => ({
          ...(item.product_id ? { product_id: item.product_id } : {}),
          description: item.description,
          quantity: Number(item.quantity),
          unit: item.unit,
          unit_price: Number(item.unit_price),
          vat_rate_id: Number(item.vat_rate_id),
          ...(item.discount_percent ? { discount_percent: Number(item.discount_percent) } : {}),
        })),
      }
      const res = await invoices.create(payload)
      const inv = res.data.data
      setIssuedResult({ id: inv.id, number: inv.invoice_number })
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? t('common.error'))
    } finally {
      setSaving(false)
    }
  }

  // Derived values for modal summary
  const partnerName = partnerList.find((p) => p.id == form.partner_id)?.name ?? '—'
  const payMethodName = payMethods.find((m) => m.id == form.payment_method_id)?.name ?? '—'
  const grandTotal = items.reduce((sum, item) => sum + lineGross(item), 0)

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('invoice.new_title')}</h1>
        <Link to="/invoices" className="btn btn-secondary">{t('common.back')}</Link>
      </div>
      {error && !showModal && <div className="alert-error mb-4">{error}</div>}
      <form onSubmit={handleSubmit}>
        <div className="card">
          <div style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 16 }}>
            <div className="form-group">
              <label>{t('invoice.partner_col')}</label>
              <select value={form.partner_id} onChange={(e) => setField('partner_id', e.target.value)} required>
                <option value="">— —</option>
                {partnerList.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label>{t('invoice.pay_method')}</label>
              <select value={form.payment_method_id} onChange={(e) => setField('payment_method_id', e.target.value)} required>
                {payMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label>{t('invoice.issue_date')}</label>
              <input type="date" value={form.issue_date} onChange={(e) => setField('issue_date', e.target.value)} required />
            </div>
            <div className="form-group">
              <label>{t('invoice.fulfillment')}</label>
              <input type="date" value={form.fulfillment_date} onChange={(e) => setField('fulfillment_date', e.target.value)} required />
            </div>
            <div className="form-group">
              <label>{t('invoice.due_date')}</label>
              <input type="date" value={form.due_date} onChange={(e) => setField('due_date', e.target.value)} required />
            </div>
            <div className="form-group">
              <label>{t('common.currency')}</label>
              <select value={form.currency} onChange={(e) => setField('currency', e.target.value)} required>
                <option value="HUF">HUF</option>
                <option value="EUR">EUR</option>
              </select>
            </div>
          </div>
          <div className="form-group">
            <label>{t('invoice.notes')}</label>
            <textarea rows={2} value={form.notes} onChange={(e) => setField('notes', e.target.value)} />
          </div>
        </div>

        <div className="card">
          <strong>{t('invoice.items')}</strong>
          <table className="items-table mt-4">
            <thead>
              <tr>
                <th style={{ width: '30%' }}>{t('invoice.product_desc')}</th>
                <th>{t('invoice.unit')}</th>
                <th>{t('invoice.quantity')}</th>
                <th>{t('invoice.unit_price')}</th>
                <th>{t('invoice.vat')}</th>
                <th>{t('invoice.discount')}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i}>
                  <td>
                    <ProductComboBox
                      selectedProduct={item._product ?? null}
                      onSelect={(product) => handleProductSelect(i, product)}
                      onClear={() => handleProductClear(i)}
                    />
                  </td>
                  <td>
                    <input
                      value={item.unit}
                      onChange={(e) => setItem(i, 'unit', e.target.value)}
                      style={{ width: 60 }}
                    />
                  </td>
                  <td>
                    <input
                      type="number" step="0.001" value={item.quantity}
                      onChange={(e) => setItem(i, 'quantity', e.target.value)}
                      style={{ width: 80 }} required
                    />
                  </td>
                  <td>
                    <input
                      type="number" step="0.01" value={item.unit_price}
                      onChange={(e) => setItem(i, 'unit_price', e.target.value)}
                      style={{ width: 100 }} required
                    />
                  </td>
                  <td>
                    <select
                      value={item.vat_rate_id}
                      onChange={(e) => setItem(i, 'vat_rate_id', e.target.value)}
                      required style={{ width: 120 }}
                    >
                      {vatRates.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                    </select>
                  </td>
                  <td>
                    <input
                      type="number" step="0.01" value={item.discount_percent}
                      onChange={(e) => setItem(i, 'discount_percent', e.target.value)}
                      style={{ width: 70 }} placeholder="0"
                    />
                  </td>
                  <td>
                    {items.length > 1 && (
                      <button type="button" className="btn btn-danger btn-sm" onClick={() => removeItem(i)}>×</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <button type="button" className="btn btn-secondary btn-sm mt-4" onClick={addItem}>{t('invoice.add_item')}</button>
        </div>

        <div className="flex">
          <button className="btn btn-primary" type="submit">
            {t('invoice.submit')}
          </button>
          <Link to="/invoices" className="btn btn-secondary">{t('common.cancel')}</Link>
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
              // Success state — invoice number from server response
              <>
                <div className="alert-success mb-4" style={{ fontSize: 15 }}>
                  {t('invoice.issued_ok', { number: issuedResult.number })}
                </div>
                <div className="flex">
                  <button className="btn btn-primary" onClick={() => navigate(`/invoices/${issuedResult.id}`)}>
                    {t('invoice.view_detail')}
                  </button>
                  <Link to="/documents" className="btn btn-secondary">{t('common.back_to_list')}</Link>
                </div>
              </>
            ) : (
              // Confirmation state — summary without invoice number
              <>
                <h2 style={{ marginTop: 0, marginBottom: 16 }}>{t('invoice.confirm_title')}</h2>
                {error && <div className="alert-error mb-4">{error}</div>}

                <table style={{ width: '100%', marginBottom: 16, borderCollapse: 'collapse' }}>
                  <tbody>
                    {[
                      [t('invoice.partner_col'), <strong key="p">{partnerName}</strong>],
                      [t('invoice.pay_method'), payMethodName],
                      [t('invoice.issue_date'), form.issue_date],
                      [t('invoice.fulfillment'), form.fulfillment_date],
                      [t('invoice.due_date'), form.due_date],
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
