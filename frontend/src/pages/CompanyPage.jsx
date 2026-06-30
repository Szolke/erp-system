import { useEffect, useState } from 'react'
import { company as companyApi } from '../api/company'
import { useAuth } from '../contexts/AuthContext'

export default function CompanyPage() {
  const { can } = useAuth()
  const [form, setForm] = useState(null)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    companyApi.get().then((res) => setForm(res.data.data))
  }, [])

  function setField(k, v) { setForm((f) => ({ ...f, [k]: v })); setSuccess(false) }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSaving(true)
    try {
      await companyApi.update(form)
      setSuccess(true)
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? 'Hiba')
    } finally { setSaving(false) }
  }

  if (!form) return <p className="text-muted">Betöltés…</p>

  const fields = [
    ['name', 'Cégnév', 'text', true], ['tax_number', 'Adószám', 'text', true],
    ['eu_tax_number', 'Közösségi adószám', 'text', false], ['registration_number', 'Cégjegyzékszám', 'text', true],
    ['postal_code', 'Irányítószám', 'text', true], ['city', 'Város', 'text', true],
    ['address_line', 'Cím', 'text', true], ['email', 'E-mail', 'email', false],
    ['phone', 'Telefon', 'text', false], ['base_currency', 'Alap deviza', 'text', true],
  ]

  return (
    <div>
      <div className="page-header"><h1 className="page-title">Cégbeállítások</h1></div>
      {error && <div className="alert-error mb-4">{error}</div>}
      {success && <div className="mb-4" style={{ background: '#dcfce7', color: '#15803d', padding: '10px 12px', borderRadius: 5 }}>Mentve.</div>}
      <form onSubmit={handleSubmit}>
        <div className="card" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          {fields.map(([key, label, type, req]) => (
            <div className="form-group" key={key}>
              <label>{label}</label>
              <input type={type} value={form[key] ?? ''} onChange={(e) => setField(key, e.target.value)} required={req} />
            </div>
          ))}
          {can('company.manage') && (
            <div className="form-group">
              <label>NAV környezet</label>
              <select value={form.nav_environment ?? 'test'} onChange={(e) => setField('nav_environment', e.target.value)}>
                <option value="test">Teszt (sandbox)</option>
                <option value="production">Éles (production)</option>
              </select>
            </div>
          )}
        </div>
        <div className="form-group card">
          <label>Bizonylatfejléc</label>
          <textarea rows={3} value={form.invoice_header_text ?? ''} onChange={(e) => setField('invoice_header_text', e.target.value)} />
          <label style={{ marginTop: 12 }}>Bizonylatláb</label>
          <textarea rows={3} value={form.invoice_footer_text ?? ''} onChange={(e) => setField('invoice_footer_text', e.target.value)} />
        </div>
        {can('company.manage') && (
          <button className="btn btn-primary" type="submit" disabled={saving}>{saving ? 'Mentés…' : 'Mentés'}</button>
        )}
      </form>
    </div>
  )
}
