import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { partners } from '../../api/partners'
import { customFields as cfApi } from '../../api/customFields'
import { useTranslation } from '../../contexts/TranslationContext'
import CustomFieldsForm from '../../components/CustomFieldsForm'
import CountrySelect from '../../components/CountrySelect'
import BlameFooter from '../../components/BlameFooter'

const empty = { type: 'customer', name: '', tax_number: '', billing_postal_code: '', billing_city: '', billing_address_line: '', billing_country_code: 'HU', default_currency: 'HUF', email: '', phone: '', custom_fields: {} }

export default function PartnerFormPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { t } = useTranslation()
  const isEdit = !!id
  const [form, setForm]         = useState(empty)
  const [cfDefs, setCfDefs]     = useState([])
  const [error, setError]       = useState('')
  const [saving, setSaving]     = useState(false)

  useEffect(() => {
    cfApi.list('partner').then((res) => setCfDefs(res.data.data))
    if (isEdit) partners.get(id).then((res) => setForm({ ...res.data.data, custom_fields: res.data.data.custom_fields ?? {} }))
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

  const basicFields = [
    ['name', t('partner.company_name'), 'text', true],
    ['tax_number', t('partner.tax_number'), 'text', false],
    ['email', t('partner.email'), 'email', false],
    ['phone', t('partner.phone'), 'text', false],
  ]

  const addressFields = [
    ['billing_postal_code', t('partner.postal'), 'text', true],
    ['billing_city', t('partner.city'), 'text', true],
    ['billing_address_line', t('partner.address'), 'text', true],
  ]

  const hasCustomFields = cfDefs.some((d) => d.is_active)

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{isEdit ? `${t('partner.title')} – ${t('common.edit')}` : t('partner.new')}</h1>
        <Link to="/partners" className="btn btn-secondary">{t('common.back')}</Link>
      </div>
      {error && <div className="alert-error mb-4">{error}</div>}
      <form onSubmit={handleSubmit}>
        <div className="card" style={{ maxWidth: 480 }}>
          <div className="form-group">
            <label>{t('partner.type')}</label>
            <select value={form.type} onChange={(e) => setField('type', e.target.value)}>
              <option value="customer">{t('partner.type_customer')}</option>
              <option value="supplier">{t('partner.type_supplier')}</option>
              <option value="both">{t('partner.type_both')}</option>
            </select>
          </div>
          {basicFields.map(([key, label, type, req]) => (
            <div className="form-group" key={key}>
              <label>{label}</label>
              <input type={type} value={form[key] ?? ''} onChange={(e) => setField(key, e.target.value)} required={req} />
            </div>
          ))}
          <div className="form-group">
            <label>{t('common.currency')}</label>
            <select value={form.default_currency} onChange={(e) => setField('default_currency', e.target.value)}>
              <option value="HUF">HUF</option>
              <option value="EUR">EUR</option>
              <option value="USD">USD</option>
            </select>
          </div>
        </div>

        <div className="card" style={{ maxWidth: 480 }}>
          <h2 style={{ margin: '0 0 14px', fontSize: '1.1rem' }}>{t('partner.address_section')}</h2>
          {addressFields.map(([key, label, type, req]) => (
            <div className="form-group" key={key}>
              <label>{label}</label>
              <input type={type} value={form[key] ?? ''} onChange={(e) => setField(key, e.target.value)} required={req} />
            </div>
          ))}
          <div className="form-group">
            <label htmlFor="billing_country_code">{t('partner.country')}</label>
            <CountrySelect
              id="billing_country_code"
              value={form.billing_country_code}
              onChange={(code) => setField('billing_country_code', code)}
            />
          </div>
        </div>

        {hasCustomFields && (
          <div className="card" style={{ maxWidth: 480 }}>
            <h2 style={{ margin: '0 0 14px', fontSize: '1.1rem' }}>{t('nav.custom_fields')}</h2>
            <CustomFieldsForm
              definitions={cfDefs}
              values={form.custom_fields}
              onChange={(cf) => setField('custom_fields', cf)}
            />
          </div>
        )}

        <div className="flex">
          <button className="btn btn-primary" type="submit" disabled={saving}>{saving ? t('common.saving') : t('common.save')}</button>
          <Link to="/partners" className="btn btn-secondary">{t('common.cancel')}</Link>
        </div>
      </form>
      {/* A detail-válasz teljes egészében a `form`-ba kerül, így a blame-kulcsok
          is onnan jönnek; új partnernél nincsenek benne, ezért nem is renderel. */}
      <BlameFooter createdBy={form.created_by} updatedBy={form.updated_by} />
    </div>
  )
}
