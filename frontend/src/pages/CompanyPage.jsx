import { useEffect, useState } from 'react'
import { company as companyApi } from '../api/company'
import { useAuth } from '../contexts/AuthContext'
import { useTranslation } from '../contexts/TranslationContext'

function GeneralSettingsSection({ can }) {
  const { t } = useTranslation()
  const [draft, setDraft]     = useState({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving]   = useState(false)
  const [error, setError]     = useState('')
  const [success, setSuccess] = useState(false)

  useEffect(() => { loadSettings() }, [])

  async function loadSettings() {
    setLoading(true)
    try {
      const res = await companyApi.settings.getAll()
      const map = {}
      res.data.data.forEach((s) => { map[s.key] = s.value })
      setDraft(map)
    } finally { setLoading(false) }
  }

  function setField(key, value) { setDraft((d) => ({ ...d, [key]: value })); setSuccess(false) }

  async function handleSave(e) {
    e.preventDefault()
    setSaving(true)
    setError('')
    setSuccess(false)
    try {
      await companyApi.settings.set('default_currency', draft.default_currency)
      await companyApi.settings.set('invoice_language', draft.invoice_language)
      await companyApi.settings.set('invoice_due_days', draft.invoice_due_days)
      setSuccess(true)
    } catch (err) {
      setError(err.response?.data?.message ?? t('common.error'))
    } finally { setSaving(false) }
  }

  if (loading) return null

  return (
    <div className="card" style={{ marginTop: 24 }}>
      <h2 style={{ margin: '0 0 16px', fontSize: '1.1rem' }}>{t('settings.title')}</h2>
      {error && <div className="alert-error mb-4">{error}</div>}
      {success && (
        <div className="mb-4" style={{ background: '#dcfce7', color: '#15803d', padding: '10px 12px', borderRadius: 5 }}>
          {t('common.saved')}
        </div>
      )}
      <form onSubmit={handleSave}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16 }}>
          <div className="form-group">
            <label>{t('settings.default_currency')}</label>
            <select value={draft.default_currency ?? 'HUF'} onChange={(e) => setField('default_currency', e.target.value)}>
              <option value="HUF">HUF</option>
              <option value="EUR">EUR</option>
              <option value="USD">USD</option>
            </select>
          </div>
          <div className="form-group">
            <label>{t('settings.invoice_language')}</label>
            <select value={draft.invoice_language ?? 'hu'} onChange={(e) => setField('invoice_language', e.target.value)}>
              <option value="hu">Magyar</option>
              <option value="en">English</option>
              <option value="de">Deutsch</option>
            </select>
          </div>
          <div className="form-group">
            <label>{t('settings.invoice_due_days')}</label>
            <input
              type="number"
              min={0}
              max={365}
              value={draft.invoice_due_days ?? 8}
              onChange={(e) => setField('invoice_due_days', Number(e.target.value))}
            />
          </div>
        </div>
        {can('company.manage') && (
          <button className="btn btn-primary" type="submit" disabled={saving}>
            {saving ? t('common.saving') : t('common.save')}
          </button>
        )}
      </form>
    </div>
  )
}

function SimplePaySection({ can }) {
  const { t } = useTranslation()
  const [creds, setCreds]     = useState([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(null)
  const [saving, setSaving]   = useState(false)
  const [spError, setSpError] = useState('')

  useEffect(() => { loadCreds() }, [])

  async function loadCreds() {
    setLoading(true)
    try {
      const res = await companyApi.simplePay.list()
      setCreds(res.data.data)
    } finally { setLoading(false) }
  }

  function startEdit(cred) {
    setEditing({ ...cred, secret_key: '', isNew: false })
    setSpError('')
  }

  function startAdd() {
    setEditing({ currency: 'HUF', merchant_id: '', secret_key: '', sandbox: true, is_active: true, isNew: true })
    setSpError('')
  }

  async function handleSave() {
    setSaving(true)
    setSpError('')
    try {
      const payload = { merchant_id: editing.merchant_id, sandbox: editing.sandbox, is_active: editing.is_active }
      if (editing.secret_key) payload.secret_key = editing.secret_key
      await companyApi.simplePay.upsert(editing.currency, payload)
      setEditing(null)
      await loadCreds()
    } catch (err) {
      setSpError(err.response?.data?.message ?? t('common.error'))
    } finally { setSaving(false) }
  }

  async function handleDelete(currency) {
    if (!window.confirm(t('simplepay.del_confirm'))) return
    await companyApi.simplePay.delete(currency)
    await loadCreds()
  }

  const CURRENCIES = ['HUF', 'EUR', 'USD']
  const configuredSet = new Set(creds.map((c) => c.currency))

  return (
    <div className="card" style={{ marginTop: 24 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: '1.1rem' }}>{t('simplepay.title')}</h2>
        {can('company.manage') && !editing && (
          <button className="btn btn-secondary btn-sm" onClick={startAdd}>{t('simplepay.add')}</button>
        )}
      </div>

      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <>
          {creds.length > 0 && (
            <table>
              <thead>
                <tr>
                  <th style={{ width: 80 }}>{t('common.currency')}</th>
                  <th>{t('simplepay.merchant_id')}</th>
                  <th style={{ width: 120 }}>{t('simplepay.secret_key')}</th>
                  <th style={{ width: 140 }}>{t('simplepay.sandbox')}</th>
                  <th style={{ width: 90 }}>{t('common.active')}</th>
                  <th style={{ width: 130 }}></th>
                </tr>
              </thead>
              <tbody>
                {creds.map((cred) => (
                  <tr key={cred.currency}>
                    <td><strong>{cred.currency}</strong></td>
                    <td style={{ fontFamily: 'monospace' }}>{cred.merchant_id}</td>
                    <td>
                      {cred.has_secret_key
                        ? <span style={{ color: '#15803d' }}>✓ {t('simplepay.set')}</span>
                        : <span className="text-muted">{t('simplepay.not_set')}</span>}
                    </td>
                    <td>{cred.sandbox ? t('common.yes') : t('common.no')}</td>
                    <td>{cred.is_active ? t('common.active') : t('common.inactive')}</td>
                    <td>
                      {can('company.manage') && (
                        <span style={{ display: 'flex', gap: 6 }}>
                          <button className="btn btn-secondary btn-sm" onClick={() => startEdit(cred)}>{t('common.edit')}</button>
                          <button className="btn btn-danger btn-sm" onClick={() => handleDelete(cred.currency)}>{t('common.delete')}</button>
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {creds.length === 0 && !editing && (
            <p className="text-muted">{t('simplepay.not_set')}</p>
          )}

          {editing && (
            <div className="card" style={{ marginTop: 16, background: '#f8fafc' }}>
              {spError && <div className="alert-error mb-4">{spError}</div>}
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                {editing.isNew ? (
                  <div className="form-group">
                    <label>{t('common.currency')}</label>
                    <select
                      value={editing.currency}
                      onChange={(e) => setEditing({ ...editing, currency: e.target.value })}
                    >
                      {CURRENCIES.filter((c) => !configuredSet.has(c)).map((c) => (
                        <option key={c} value={c}>{c}</option>
                      ))}
                    </select>
                  </div>
                ) : (
                  <div className="form-group">
                    <label>{t('common.currency')}</label>
                    <input value={editing.currency} disabled />
                  </div>
                )}
                <div className="form-group">
                  <label>{t('simplepay.merchant_id')}</label>
                  <input
                    value={editing.merchant_id}
                    onChange={(e) => setEditing({ ...editing, merchant_id: e.target.value })}
                  />
                </div>
                <div className="form-group">
                  <label>{editing.isNew ? t('simplepay.secret_key') : t('simplepay.new_key')}</label>
                  <input
                    type="password"
                    value={editing.secret_key}
                    onChange={(e) => setEditing({ ...editing, secret_key: e.target.value })}
                    placeholder={editing.isNew ? '' : '••••••••'}
                  />
                </div>
                <div className="form-group" style={{ alignSelf: 'end', display: 'flex', gap: 16 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={editing.sandbox}
                      onChange={(e) => setEditing({ ...editing, sandbox: e.target.checked })}
                    />
                    {t('simplepay.sandbox')}
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={editing.is_active}
                      onChange={(e) => setEditing({ ...editing, is_active: e.target.checked })}
                    />
                    {t('common.active')}
                  </label>
                </div>
              </div>
              <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                <button className="btn btn-primary" onClick={handleSave} disabled={saving}>
                  {saving ? t('common.saving') : t('common.save')}
                </button>
                <button className="btn btn-secondary" onClick={() => setEditing(null)}>{t('common.cancel')}</button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

export default function CompanyPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
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

  if (!form) return <p className="text-muted">{t('common.loading')}</p>

  const fields = [
    ['name', t('company.name'), 'text', true], ['tax_number', t('company.tax_number'), 'text', true],
    ['eu_tax_number', t('company.eu_tax'), 'text', false], ['registration_number', t('company.reg_number'), 'text', true],
    ['postal_code', t('company.postal'), 'text', true], ['city', t('company.city'), 'text', true],
    ['address_line', t('company.address'), 'text', true], ['email', t('common.email'), 'email', false],
    ['phone', 'Telefon', 'text', false], ['base_currency', t('company.base_currency'), 'text', true],
  ]

  return (
    <div>
      <div className="page-header"><h1 className="page-title">{t('company.title')}</h1></div>
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
              <label>{t('company.nav_env')}</label>
              <select value={form.nav_environment ?? 'test'} onChange={(e) => setField('nav_environment', e.target.value)}>
                <option value="test">{t('company.nav_test')}</option>
                <option value="production">{t('company.nav_prod')}</option>
              </select>
            </div>
          )}
        </div>
        <div className="form-group card">
          <label>{t('company.inv_header')}</label>
          <textarea rows={3} value={form.invoice_header_text ?? ''} onChange={(e) => setField('invoice_header_text', e.target.value)} />
          <label style={{ marginTop: 12 }}>{t('company.inv_footer')}</label>
          <textarea rows={3} value={form.invoice_footer_text ?? ''} onChange={(e) => setField('invoice_footer_text', e.target.value)} />
        </div>
        {can('company.manage') && (
          <button className="btn btn-primary" type="submit" disabled={saving}>{saving ? t('common.saving') : t('common.save')}</button>
        )}
      </form>

      {can('company.manage') && <GeneralSettingsSection can={can} />}
      {can('company.manage') && <SimplePaySection can={can} />}
    </div>
  )
}
