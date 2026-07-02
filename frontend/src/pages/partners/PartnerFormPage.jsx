import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { partners } from '../../api/partners'
import { useTranslation } from '../../contexts/TranslationContext'

const empty = { type: 'customer', name: '', tax_number: '', billing_postal_code: '', billing_city: '', billing_address_line: '', default_currency: 'HUF', email: '', phone: '' }

export default function PartnerFormPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { t } = useTranslation()
  const isEdit = !!id
  const [form, setForm] = useState(empty)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (isEdit) partners.get(id).then((res) => setForm(res.data.data))
  }, [id])

  function setField(k, v) { setForm((f) => ({ ...f, [k]: v })) }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSaving(true)
    try {
      if (isEdit) { await partners.update(id, form) } else { await partners.create(form) }
      navigate('/partners')
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? 'Hiba')
    } finally { setSaving(false) }
  }

  const fields = [
    ['name', t('partner.company_name'), 'text', true], ['tax_number', t('partner.tax_number'), 'text', false], ['email', t('partner.email'), 'email', false],
    ['phone', t('partner.phone'), 'text', false], ['billing_postal_code', t('partner.postal'), 'text', true],
    ['billing_city', t('partner.city'), 'text', true], ['billing_address_line', t('partner.address'), 'text', true],
    ['default_currency', t('common.currency'), 'text', true],
  ]

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{isEdit ? `${t('partner.title')} – ${t('common.edit')}` : t('partner.new')}</h1>
        <Link to="/partners" className="btn btn-secondary">{t('common.back')}</Link>
      </div>
      {error && <div className="alert-error mb-4">{error}</div>}
      <form onSubmit={handleSubmit}>
        <div className="card" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <div className="form-group">
            <label>Típus</label>
            <select value={form.type} onChange={(e) => setField('type', e.target.value)}>
              <option value="customer">Vevő</option>
              <option value="supplier">Szállító</option>
              <option value="both">Mindkettő</option>
            </select>
          </div>
          {fields.map(([key, label, type, req]) => (
            <div className="form-group" key={key}>
              <label>{label}</label>
              <input type={type} value={form[key] ?? ''} onChange={(e) => setField(key, e.target.value)} required={req} />
            </div>
          ))}
        </div>
        <div className="flex">
          <button className="btn btn-primary" type="submit" disabled={saving}>{saving ? t('common.saving') : t('common.save')}</button>
          <Link to="/partners" className="btn btn-secondary">{t('common.cancel')}</Link>
        </div>
      </form>
    </div>
  )
}
