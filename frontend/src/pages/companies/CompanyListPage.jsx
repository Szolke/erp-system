import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { companies as companiesApi } from '../../api/company'

const EMPTY_FORM = { name: '', tax_number: '', city: '', address_line: '', postal_code: '', registration_number: '', email: '' }

export default function CompanyListPage() {
  const { user, activeCompanyId, switchCompany, refreshAuth } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()

  const [data, setData]         = useState(null)
  const [loading, setLoading]   = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm]         = useState(EMPTY_FORM)
  const [formErr, setFormErr]   = useState('')
  const [saving, setSaving]     = useState(false)
  const [switching, setSwitching] = useState(null)

  async function load() {
    setLoading(true)
    try {
      const res = await companiesApi.list()
      setData(res.data)
    } finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  function setField(e) {
    setForm((prev) => ({ ...prev, [e.target.name]: e.target.value }))
  }

  async function handleCreate(e) {
    e.preventDefault()
    setFormErr('')
    setSaving(true)
    try {
      await companiesApi.create(form)
      setForm(EMPTY_FORM)
      setShowForm(false)
      toast('Cég sikeresen létrehozva', 'success')
      await refreshAuth()
      load()
    } catch (err) {
      const errs = err.response?.data?.errors
      setFormErr(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? t('common.error'))
    } finally { setSaving(false) }
  }

  async function handleSwitch(company) {
    setSwitching(company.id)
    try {
      await switchCompany(company.id)
      navigate('/documents')
    } finally { setSwitching(null) }
  }

  const list = data?.data ?? []

  if (!user?.is_superadmin) {
    return <p className="text-muted">{t('common.no_permission')}</p>
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Cégek</h1>
        <button className="btn btn-primary" onClick={() => setShowForm(!showForm)}>
          {showForm ? t('common.cancel') : '+ Új cég'}
        </button>
      </div>

      {showForm && (
        <div className="card" style={{ marginBottom: 20 }}>
          <strong>Új cég létrehozása</strong>
          <p className="text-muted mt-4" style={{ fontSize: 12 }}>
            A cég létrehozása után 4 alapértelmezett bizonylat-sorozat (SZ / NY / SZSZT / NYSZT) automatikusan létrejön.
            A többi adatot a Cégbeállítások oldalon lehet megadni.
          </p>
          {formErr && <div className="alert-error mt-4">{formErr}</div>}
          <form onSubmit={handleCreate} style={{ marginTop: 12 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 12 }}>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Cég neve *</label>
                <input name="name" value={form.name} onChange={setField} required placeholder="pl. Teszt Bt." />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Adószám * (12345678-1-42)</label>
                <input name="tax_number" value={form.tax_number} onChange={setField} required placeholder="12345678-1-42" />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Irányítószám</label>
                <input name="postal_code" value={form.postal_code} onChange={setField} placeholder="1000" />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Város</label>
                <input name="city" value={form.city} onChange={setField} placeholder="Budapest" />
              </div>
              <div className="form-group" style={{ margin: 0, gridColumn: '1 / -1' }}>
                <label>Cím</label>
                <input name="address_line" value={form.address_line} onChange={setField} placeholder="Példa utca 1." />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Cégjegyzékszám</label>
                <input name="registration_number" value={form.registration_number} onChange={setField} placeholder="01-09-000001" />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>E-mail</label>
                <input name="email" type="email" value={form.email} onChange={setField} />
              </div>
            </div>
            <div style={{ marginTop: 12, display: 'flex', gap: 8 }}>
              <button className="btn btn-primary" type="submit" disabled={saving}>
                {saving ? t('common.saving') : 'Létrehozás'}
              </button>
              <button className="btn btn-secondary" type="button" onClick={() => { setShowForm(false); setFormErr('') }}>
                {t('common.cancel')}
              </button>
            </div>
          </form>
        </div>
      )}

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Cégnév</th>
              <th>Adószám</th>
              <th>Város</th>
              <th>Felhasználók</th>
              <th>Státusz</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {list.length === 0 && (
              <tr><td colSpan={6} className="text-muted">{t('common.not_found')}</td></tr>
            )}
            {list.map((c) => (
              <tr key={c.id} style={c.id === activeCompanyId ? { background: 'var(--color-primary-subtle)' } : {}}>
                <td>
                  <strong>{c.name}</strong>
                  {c.id === activeCompanyId && (
                    <span className="badge badge-inv-issued" style={{ marginLeft: 8 }}>Aktív</span>
                  )}
                </td>
                <td className="text-muted">{c.tax_number}</td>
                <td className="text-muted">{c.city || '—'}</td>
                <td>{c.users_count ?? 0}</td>
                <td>
                  <span className={c.is_active ? 'badge badge-pay-paid' : 'badge badge-inv-storno'}>
                    {c.is_active ? t('common.active') : 'Inaktív'}
                  </span>
                </td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  {c.id !== activeCompanyId && (
                    <button
                      className="btn btn-secondary btn-sm"
                      onClick={() => handleSwitch(c)}
                      disabled={switching === c.id}
                    >
                      {switching === c.id ? '…' : 'Váltás'}
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && (
        <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total ?? list.length} {t('common.pieces')}</p>
      )}
    </div>
  )
}
