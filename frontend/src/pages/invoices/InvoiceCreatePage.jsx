import { useEffect, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { invoices } from '../../api/invoices'
import { partners } from '../../api/partners'
import client from '../../api/client'
import ProductComboBox from '../../components/ProductComboBox'

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
      description: product.name,
      unit: product.unit,
      unit_price: product.base_price,
      vat_rate_id: product.vat_rate_id,
    } : item))
  }

  function addItem() {
    setItems((it) => [...it, emptyItem(defaultVatId)])
  }

  function removeItem(i) {
    setItems((it) => it.filter((_, idx) => idx !== i))
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSaving(true)
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
      navigate(`/invoices/${res.data.data.id}`)
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? 'Hiba')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Új számla</h1>
        <Link to="/invoices" className="btn btn-secondary">← Vissza</Link>
      </div>
      {error && <div className="alert-error mb-4">{error}</div>}
      <form onSubmit={handleSubmit}>
        <div className="card">
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <div className="form-group">
              <label>Partner</label>
              <select value={form.partner_id} onChange={(e) => setField('partner_id', e.target.value)} required>
                <option value="">— válassz —</option>
                {partnerList.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label>Fizetési mód</label>
              <select value={form.payment_method_id} onChange={(e) => setField('payment_method_id', e.target.value)} required>
                {payMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label>Kiállítás</label>
              <input type="date" value={form.issue_date} onChange={(e) => setField('issue_date', e.target.value)} required />
            </div>
            <div className="form-group">
              <label>Teljesítés</label>
              <input type="date" value={form.fulfillment_date} onChange={(e) => setField('fulfillment_date', e.target.value)} required />
            </div>
            <div className="form-group">
              <label>Fizetési határidő</label>
              <input type="date" value={form.due_date} onChange={(e) => setField('due_date', e.target.value)} required />
            </div>
            <div className="form-group">
              <label>Deviza</label>
              <input value={form.currency} onChange={(e) => setField('currency', e.target.value)} maxLength={3} required />
            </div>
          </div>
          <div className="form-group">
            <label>Megjegyzés</label>
            <textarea rows={2} value={form.notes} onChange={(e) => setField('notes', e.target.value)} />
          </div>
        </div>

        <div className="card">
          <strong>Tételek</strong>
          <table className="items-table mt-4">
            <thead>
              <tr>
                <th style={{ width: '30%' }}>Termék / Megnevezés</th>
                <th>Me.</th>
                <th>Mennyiség</th>
                <th>Egységár</th>
                <th>ÁFA</th>
                <th>Kedv. %</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i}>
                  <td>
                    <ProductComboBox
                      description={item.description}
                      onDescriptionChange={(val) => setItem(i, 'description', val)}
                      onSelect={(product) => handleProductSelect(i, product)}
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
          <button type="button" className="btn btn-secondary btn-sm mt-4" onClick={addItem}>+ Tétel</button>
        </div>

        <div className="flex">
          <button className="btn btn-primary" type="submit" disabled={saving}>
            {saving ? 'Mentés…' : 'Számla kiállítása'}
          </button>
          <Link to="/invoices" className="btn btn-secondary">Mégsem</Link>
        </div>
      </form>
    </div>
  )
}
