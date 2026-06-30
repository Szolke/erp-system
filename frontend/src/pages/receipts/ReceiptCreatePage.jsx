import { useEffect, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { receipts } from '../../api/receipts'
import client from '../../api/client'

const today = () => new Date().toISOString().split('T')[0]
const emptyItem = () => ({ description: '', quantity: 1, unit_price: '', vat_rate_id: '' })

export default function ReceiptCreatePage() {
  const navigate = useNavigate()
  const [vatRates, setVatRates] = useState([])
  const [payMethods, setPayMethods] = useState([])
  const [form, setForm] = useState({ payment_method_id: '', issue_date: today(), currency: 'HUF' })
  const [items, setItems] = useState([emptyItem()])
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    Promise.all([client.get('/api/vat-rates'), client.get('/api/payment-methods')]).then(([v, m]) => {
      setVatRates(v.data.data ?? [])
      setPayMethods(m.data.data ?? [])
      if (v.data.data?.[0]) setItems([{ ...emptyItem(), vat_rate_id: v.data.data[0].id }])
      if (m.data.data?.[0]) setForm((f) => ({ ...f, payment_method_id: m.data.data[0].id }))
    })
  }, [])

  function setItem(i, k, v) { setItems((it) => it.map((item, idx) => idx === i ? { ...item, [k]: v } : item)) }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSaving(true)
    try {
      const payload = { ...form, items: items.map((i) => ({ description: i.description, quantity: Number(i.quantity), unit_price: Number(i.unit_price), vat_rate_id: Number(i.vat_rate_id) })) }
      const res = await receipts.create(payload)
      navigate(`/receipts/${res.data.data.id}`)
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
        <h1 className="page-title">Új nyugta</h1>
        <Link to="/receipts" className="btn btn-secondary">← Vissza</Link>
      </div>
      {error && <div className="alert-error mb-4">{error}</div>}
      <form onSubmit={handleSubmit}>
        <div className="card">
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <div className="form-group">
              <label>Fizetési mód</label>
              <select value={form.payment_method_id} onChange={(e) => setForm({ ...form, payment_method_id: e.target.value })} required>
                {payMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label>Kelt</label>
              <input type="date" value={form.issue_date} onChange={(e) => setForm({ ...form, issue_date: e.target.value })} required />
            </div>
          </div>
        </div>
        <div className="card">
          <table className="items-table">
            <thead><tr><th>Megnevezés</th><th>Mennyiség</th><th>Egységár</th><th>ÁFA</th><th></th></tr></thead>
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
          <button type="button" className="btn btn-secondary btn-sm mt-4" onClick={() => setItems((it) => [...it, { ...emptyItem(), vat_rate_id: vatRates[0]?.id ?? '' }])}>+ Tétel</button>
        </div>
        <div className="flex">
          <button className="btn btn-primary" disabled={saving}>{saving ? 'Mentés…' : 'Nyugta kiállítása'}</button>
          <Link to="/receipts" className="btn btn-secondary">Mégsem</Link>
        </div>
      </form>
    </div>
  )
}
