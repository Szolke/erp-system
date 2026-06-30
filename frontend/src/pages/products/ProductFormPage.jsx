import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { products } from '../../api/products'
import client from '../../api/client'

const empty = { sku: '', name: '', description: '', unit: 'db', type: 'product', vat_rate_id: '', base_price: '', base_currency: 'HUF', is_active: true }

export default function ProductFormPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const isEdit = !!id
  const [form, setForm] = useState(empty)
  const [vatRates, setVatRates] = useState([])
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    client.get('/api/vat-rates').then((res) => {
      setVatRates(res.data.data ?? [])
      if (!isEdit && res.data.data?.[0]) setForm((f) => ({ ...f, vat_rate_id: res.data.data[0].id }))
    })
    if (isEdit) products.get(id).then((res) => setForm(res.data.data))
  }, [id])

  function setField(k, v) { setForm((f) => ({ ...f, [k]: v })) }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSaving(true)
    try {
      if (isEdit) { await products.update(id, form) } else { await products.create(form) }
      navigate('/products')
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? 'Hiba')
    } finally { setSaving(false) }
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{isEdit ? 'Termék szerkesztése' : 'Új termék / szolgáltatás'}</h1>
        <Link to="/products" className="btn btn-secondary">← Vissza</Link>
      </div>
      {error && <div className="alert-error mb-4">{error}</div>}
      <form onSubmit={handleSubmit}>
        <div className="card" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <div className="form-group"><label>Cikkszám</label><input value={form.sku} onChange={(e) => setField('sku', e.target.value)} required /></div>
          <div className="form-group"><label>Típus</label>
            <select value={form.type} onChange={(e) => setField('type', e.target.value)}>
              <option value="product">Termék</option><option value="service">Szolgáltatás</option>
            </select>
          </div>
          <div className="form-group" style={{ gridColumn: '1/-1' }}><label>Megnevezés</label><input value={form.name} onChange={(e) => setField('name', e.target.value)} required /></div>
          <div className="form-group"><label>Mértékegység</label><input value={form.unit} onChange={(e) => setField('unit', e.target.value)} required /></div>
          <div className="form-group">
            <label>ÁFA kulcs</label>
            <select value={form.vat_rate_id} onChange={(e) => setField('vat_rate_id', e.target.value)} required>
              {vatRates.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
            </select>
          </div>
          <div className="form-group"><label>Alapár</label><input type="number" step="0.01" value={form.base_price} onChange={(e) => setField('base_price', e.target.value)} required /></div>
          <div className="form-group"><label>Deviza</label><input value={form.base_currency} onChange={(e) => setField('base_currency', e.target.value)} maxLength={3} required /></div>
        </div>
        <div className="flex">
          <button className="btn btn-primary" type="submit" disabled={saving}>{saving ? 'Mentés…' : 'Mentés'}</button>
          <Link to="/products" className="btn btn-secondary">Mégsem</Link>
        </div>
      </form>
    </div>
  )
}
