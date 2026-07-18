import { useEffect, useRef, useState } from 'react'
import { company as companyApi } from '../api/company'
import { salesGroups as sgApi } from '../api/salesGroups'
import { useAuth } from '../contexts/AuthContext'
import { useTranslation } from '../contexts/TranslationContext'
import { applySidebarTheme } from '../utils/sidebarTheme'

const ACCENT_PALETTE = [
  { hex: '#0f172a', label: 'Slate 950'    },
  { hex: '#1e293b', label: 'Slate 800'    },
  { hex: '#1e3a5f', label: 'Kék-sötét'   },
  { hex: '#374151', label: 'Szürke 700'   },
  { hex: '#134e4a', label: 'Teal'         },
  { hex: '#14532d', label: 'Zöld-sötét'  },
  { hex: '#3b0764', label: 'Lila-sötét'  },
  { hex: '#4c0519', label: 'Bordó'        },
  { hex: '#78350f', label: 'Arany-sötét' },
  { hex: '#1d4ed8', label: 'Kék 700'     },
  { hex: '#047857', label: 'Emerald 700'  },
  { hex: '#7c3aed', label: 'Violet 600'   },
  { hex: '#be123c', label: 'Rose 700'     },
  { hex: '#bfdbfe', label: 'Kék 200'     },
  { hex: '#d1fae5', label: 'Emerald 200'  },
  { hex: '#ede9fe', label: 'Lila 200'     },
  { hex: '#fef9c3', label: 'Sárga 200'   },
  { hex: '#f1f5f9', label: 'Slate 100'    },
]

function AccentColorSection({ can }) {
  const { t } = useTranslation()
  const [current, setCurrent] = useState('#1e293b')
  const [saving, setSaving]   = useState(false)
  const [msg, setMsg]         = useState('')

  useEffect(() => {
    companyApi.settings.getAll().then((res) => {
      const s = res.data.data.find((x) => x.key === 'sidebar_accent_color')
      if (s) setCurrent(s.value)
    })
  }, [])

  async function handleSelect(hex) {
    if (hex === current || !can('company.manage')) return
    setSaving(true)
    setMsg('')
    try {
      await companyApi.settings.set('sidebar_accent_color', hex)
      setCurrent(hex)
      applySidebarTheme(hex)
      setMsg('ok')
    } catch {
      setMsg('err')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <h2 style={{ margin: '0 0 14px', fontSize: '1.1rem' }}>{t('settings.sidebar_accent_color')}</h2>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6, 42px)', gap: 10 }}>
        {ACCENT_PALETTE.map(({ hex, label }) => (
          <button
            key={hex}
            type="button"
            title={label}
            onClick={() => handleSelect(hex)}
            disabled={saving || !can('company.manage')}
            style={{
              width: 42,
              height: 42,
              borderRadius: 8,
              background: hex,
              border: '1px solid rgba(0,0,0,0.15)',
              cursor: can('company.manage') ? 'pointer' : 'default',
              outline: hex === current ? '3px solid white' : '3px solid transparent',
              outlineOffset: 3,
              boxShadow: hex === current ? '0 0 0 5px var(--color-primary)' : 'none',
              transition: 'box-shadow 0.15s, outline 0.15s',
            }}
          />
        ))}
      </div>
      {msg === 'ok'  && <div className="mt-4 alert-success">{t('common.saved')}</div>}
      {msg === 'err' && <div className="alert-error mt-4">{t('common.error')}</div>}
    </div>
  )
}

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
        <div className="mb-4 alert-success">{t('common.saved')}</div>
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
                        ? <span style={{ color: 'var(--color-success-text)' }}>✓ {t('simplepay.set')}</span>
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
            <div className="card" style={{ marginTop: 16, background: 'var(--color-surface-alt)' }}>
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

function SalesGroupPrefixSection({ can }) {
  const { t } = useTranslation()
  const [prefix, setPrefix]         = useState('')
  const [original, setOriginal]     = useState('')
  const [company, setCompany]       = useState(null)
  const [groupsExist, setGroupsExist] = useState(false)
  const [loading, setLoading]       = useState(true)
  const [saving, setSaving]         = useState(false)
  const [error, setError]           = useState('')
  const [success, setSuccess]       = useState(false)
  const [confirmModal, setConfirmModal] = useState(false)

  useEffect(() => { loadData() }, [])

  async function loadData() {
    setLoading(true)
    try {
      const [compRes, sgRes] = await Promise.all([
        companyApi.get(),
        sgApi.list({ per_page: 1 }),
      ])
      setCompany(compRes.data.data)
      const p = compRes.data.data?.group_prefix ?? ''
      setPrefix(p)
      setOriginal(p)
      setGroupsExist((sgRes.data.meta?.total ?? 0) > 0)
    } finally {
      setLoading(false)
    }
  }

  const dirty = prefix !== original

  async function doSave() {
    setSaving(true)
    setError('')
    setSuccess(false)
    try {
      await companyApi.update({ ...company, group_prefix: prefix || null })
      setOriginal(prefix)
      setSuccess(true)
      // Ha prefixet töröltünk/módosítottunk, frissítjük a csoportlista-állapotot
      if (!prefix) setGroupsExist(false)
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? t('common.error'))
    } finally {
      setSaving(false) }
  }

  async function handleSave(e) {
    e.preventDefault()
    setError('')
    setSuccess(false)
    // Ha módosítjuk (nem töröljük) a prefixet és vannak csoportok → megerősítő modal
    if (dirty && prefix && groupsExist && prefix !== original) {
      setConfirmModal(true)
      return
    }
    await doSave()
  }

  if (loading) return null

  return (
    <div className="card" id="section-sales-group-prefix" style={{ marginTop: 24 }}>
      <h2 style={{ margin: '0 0 16px', fontSize: '1.1rem' }}>Értékesítő csoport prefix</h2>
      <p className="text-muted" style={{ fontSize: 13, marginBottom: 14 }}>
        Legfeljebb 4 nagybetűs karakter (pl. <code>BUD</code>). A prefix az értékesítő csoportok
        megjelenítőnevének első tagja: <code>PREFIX_CsoportNév</code>. A prefixet nem lehet törölni,
        amíg van legalább egy értékesítő csoport.
      </p>
      {error && <div className="alert-error mb-4">{error}</div>}
      {success && <div className="mb-4 alert-success">{t('common.saved')}</div>}
      <form onSubmit={handleSave}>
        <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12 }}>
          <div className="form-group" style={{ margin: 0, flex: '0 0 180px' }}>
            <label>Prefix</label>
            <input
              value={prefix}
              onChange={(e) => { setPrefix(e.target.value.toUpperCase()); setSuccess(false) }}
              maxLength={4}
              placeholder="pl. BUD"
              style={{ textTransform: 'uppercase' }}
              disabled={!can('company.manage')}
            />
          </div>
          {can('company.manage') && (
            <button className="btn btn-primary" type="submit" disabled={saving || !dirty}>
              {saving ? t('common.saving') : t('common.save')}
            </button>
          )}
        </div>
      </form>

      {/* Megerősítő modal — prefix módosítása meglévő csoportokkal */}
      {confirmModal && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
        }}>
          <div className="card" style={{ maxWidth: 460, width: '90%', margin: 0 }}>
            <h3 style={{ margin: '0 0 14px', fontSize: '1rem' }}>Prefix módosítása</h3>
            <div className="alert-error mb-4" style={{ fontWeight: 500 }}>
              ⚠ A prefix módosítása az összes meglévő csoport megjelenített nevét megváltoztatja
              (pl. <code>{original}_CsoportNév</code> → <code>{prefix}_CsoportNév</code>).
            </div>
            <p style={{ marginBottom: 16, fontSize: '0.9rem' }}>Biztosan módosítod a prefixet?</p>
            <div style={{ display: 'flex', gap: 8 }}>
              <button
                className="btn btn-primary"
                onClick={async () => { setConfirmModal(false); await doSave() }}
                disabled={saving}
              >
                {saving ? 'Mentés...' : 'Igen, módosítom'}
              </button>
              <button
                className="btn btn-secondary"
                onClick={() => setConfirmModal(false)}
                disabled={saving}
              >
                {t('common.cancel')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const NAV_ENVS   = ['test', 'production']
const NAV_LABELS = { test: 'Teszt', production: 'Éles' }

const NAV_SECRET_FIELDS = [
  ['nav_login',        'Bejelentkezési név'],
  ['nav_password',     'Jelszó'],
  ['nav_signing_key',  'Aláírókulcs'],
  ['nav_exchange_key', 'Cserekulcs'],
]

function NavSection({ can }) {
  const { t } = useTranslation()

  const [navData, setNavData]         = useState(null)
  const [loading, setLoading]         = useState(true)
  const [editing, setEditing]         = useState(null)   // { environment, nav_tax_number, nav_login, …, is_active, isNew }
  const [saving, setSaving]           = useState(false)
  const [formError, setFormError]     = useState('')
  const [switchTarget, setSwitchTarget] = useState(null) // 'test' | 'production'
  const [switching, setSwitching]     = useState(false)
  const [switchError, setSwitchError] = useState('')

  useEffect(() => { loadNav() }, [])

  async function loadNav() {
    setLoading(true)
    try {
      const res = await companyApi.nav.list()
      setNavData(res.data)
    } catch {
      /* 404 = modul ki, nem renderelődik ide */
    } finally { setLoading(false) }
  }

  const activeEnv  = navData?.active_environment
  const credForEnv = (env) => navData?.credentials.find((c) => c.environment === env) ?? null

  // Figyelmeztető sáv feltétele: az aktív environmenthez nincs is_active=true sor
  const showWarning = !!navData && !navData.credentials.some(
    (c) => c.environment === activeEnv && c.is_active,
  )

  function startAdd(env) {
    setEditing({ environment: env, nav_tax_number: '', nav_login: '', nav_password: '',
                 nav_signing_key: '', nav_exchange_key: '', is_active: true, isNew: true })
    setFormError('')
  }

  function startEdit(cred) {
    // Titkos mezők üresen kezdenek — üres = meglévő érték megmarad (backend konvenció)
    setEditing({ environment: cred.environment, nav_tax_number: cred.nav_tax_number,
                 nav_login: '', nav_password: '', nav_signing_key: '', nav_exchange_key: '',
                 is_active: cred.is_active, isNew: false })
    setFormError('')
  }

  async function handleSave() {
    setSaving(true)
    setFormError('')
    try {
      const payload = { nav_tax_number: editing.nav_tax_number, is_active: editing.is_active }
      // Titkos mezők csak ha nem üresek — üres = backend megtartja a tárolt értéket
      NAV_SECRET_FIELDS.forEach(([f]) => { if (editing[f]) payload[f] = editing[f] })
      await companyApi.nav.upsert(editing.environment, payload)
      setEditing(null)
      await loadNav()
    } catch (err) {
      setFormError(err.response?.data?.message ?? t('common.error'))
    } finally { setSaving(false) }
  }

  async function handleDelete(env) {
    if (!window.confirm(`Biztosan törlöd a(z) ${NAV_LABELS[env]} environment hitelesítőjét?`)) return
    try {
      await companyApi.nav.delete(env)
      await loadNav()
    } catch (err) {
      // A backend 422-t ad, ha az aktív environmentet próbálják törölni
      alert(err.response?.data?.message ?? t('common.error'))
    }
  }

  async function confirmSwitch() {
    setSwitching(true)
    setSwitchError('')
    try {
      await companyApi.nav.setActiveEnvironment(switchTarget)
      setSwitchTarget(null)
      await loadNav()
    } catch (err) {
      setSwitchError(err.response?.data?.message ?? t('common.error'))
    } finally { setSwitching(false) }
  }

  return (
    <div className="card" style={{ marginTop: 24 }}>
      <h2 style={{ margin: '0 0 16px', fontSize: '1.1rem' }}>NAV Online Számla</h2>

      {/* ── Figyelmeztető sáv ── */}
      {showWarning && (
        <div className="alert-error mb-4" style={{ fontWeight: 500 }}>
          ⚠ Az aktív beküldési környezethez ({NAV_LABELS[activeEnv]}) nincs aktív NAV-hitelesítő
          beállítva — a számlák <strong>NEM kerülnek beküldésre</strong> a NAV-hoz.
        </div>
      )}

      {loading ? <p className="text-muted">{t('common.loading')}</p> : navData && (
        <>
          {/* ── Beküldési környezet váltó ── */}
          <div style={{
            display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 10,
            padding: '12px 16px', background: 'var(--color-surface-alt)',
            borderRadius: 8, marginBottom: 20,
          }}>
            <span style={{ fontSize: '0.85rem', color: 'var(--color-muted)', marginRight: 4 }}>
              Beküldési környezet:
            </span>
            {NAV_ENVS.map((env) => (
              <span key={env} style={{
                padding: '3px 12px', borderRadius: 16, fontSize: '0.82rem', fontWeight: 600,
                background: activeEnv === env ? 'var(--color-primary)' : 'transparent',
                color:      activeEnv === env ? '#fff' : 'var(--color-muted)',
                border: `1px solid ${activeEnv === env ? 'var(--color-primary)' : 'var(--color-border)'}`,
              }}>
                {NAV_LABELS[env]}
              </span>
            ))}
            {can('company.manage') && (
              <button
                className="btn btn-secondary btn-sm"
                style={{ marginLeft: 'auto' }}
                onClick={() => { setSwitchError(''); setSwitchTarget(activeEnv === 'test' ? 'production' : 'test') }}
              >
                Váltás {activeEnv === 'test' ? 'élesre →' : '← tesztre'}
              </button>
            )}
          </div>

          {/* ── Credential panelek ── */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            {NAV_ENVS.map((env) => {
              const cred       = credForEnv(env)
              const isActiveEnv = env === activeEnv
              return (
                <div key={env} style={{
                  padding: 16, borderRadius: 8,
                  background: 'var(--color-surface-alt)',
                  border: `${isActiveEnv ? 2 : 1}px solid ${isActiveEnv ? 'var(--color-primary)' : 'var(--color-border)'}`,
                }}>
                  {/* Panel fejléc */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
                    <strong style={{ fontSize: '0.95rem' }}>{NAV_LABELS[env]}</strong>
                    {isActiveEnv && (
                      <span style={{
                        fontSize: '0.68rem', fontWeight: 700, letterSpacing: '0.02em',
                        background: 'var(--color-primary)', color: '#fff', borderRadius: 10, padding: '1px 8px',
                      }}>
                        AKTÍV
                      </span>
                    )}
                  </div>

                  {cred ? (
                    <>
                      <div style={{ fontSize: '0.84rem', display: 'grid', gap: 5, marginBottom: 14 }}>
                        <div>
                          <span style={{ color: 'var(--color-muted)' }}>Adószám: </span>
                          <code style={{ fontSize: '0.82rem' }}>{cred.nav_tax_number}</code>
                        </div>
                        {NAV_SECRET_FIELDS.map(([hasKey, label]) => (
                          <div key={hasKey}>
                            <span style={{ color: 'var(--color-muted)' }}>{label}: </span>
                            {cred[`has_${hasKey.replace('nav_', '')}`]
                              ? <span style={{ color: 'var(--color-success-text)' }}>✓ Beállítva</span>
                              : <span style={{ color: 'var(--color-muted)' }}>— Hiányzik</span>}
                          </div>
                        ))}
                        <div style={{ marginTop: 4, paddingTop: 4, borderTop: '1px solid var(--color-border)' }}>
                          <span style={{ color: 'var(--color-muted)' }}>Aktív hitelesítő: </span>
                          {cred.is_active
                            ? <span style={{ color: 'var(--color-success-text)' }}>✓ Igen</span>
                            : <span style={{ color: 'var(--color-muted)' }}>— Nem</span>}
                        </div>
                      </div>
                      {can('company.manage') && !editing && (
                        <div style={{ display: 'flex', gap: 6 }}>
                          <button className="btn btn-secondary btn-sm" onClick={() => startEdit(cred)}>
                            {t('common.edit')}
                          </button>
                          <button className="btn btn-danger btn-sm" onClick={() => handleDelete(env)}>
                            {t('common.delete')}
                          </button>
                        </div>
                      )}
                    </>
                  ) : (
                    <>
                      <p style={{ fontSize: '0.84rem', color: 'var(--color-muted)', marginBottom: 12 }}>
                        Nincs hitelesítő beállítva.
                      </p>
                      {can('company.manage') && !editing && (
                        <button className="btn btn-secondary btn-sm" onClick={() => startAdd(env)}>
                          + Beállítás
                        </button>
                      )}
                    </>
                  )}
                </div>
              )
            })}
          </div>

          {/* ── Szerkesztő / hozzáadó űrlap ── */}
          {editing && (
            <div className="card" style={{ marginTop: 16, background: 'var(--color-surface-alt)' }}>
              <div style={{ fontWeight: 600, marginBottom: 12 }}>
                {editing.isNew
                  ? `+ Hitelesítő beállítása — ${NAV_LABELS[editing.environment]}`
                  : `Szerkesztés — ${NAV_LABELS[editing.environment]}`}
              </div>
              {formError && <div className="alert-error mb-4">{formError}</div>}
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div className="form-group">
                  <label>Adószám</label>
                  <input
                    value={editing.nav_tax_number}
                    onChange={(e) => setEditing({ ...editing, nav_tax_number: e.target.value })}
                    placeholder="12345678-1-41"
                    maxLength={13}
                  />
                </div>
                <div className="form-group" style={{ alignSelf: 'end', paddingBottom: 6 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={editing.is_active}
                      onChange={(e) => setEditing({ ...editing, is_active: e.target.checked })}
                    />
                    Aktív hitelesítő
                  </label>
                </div>
                {NAV_SECRET_FIELDS.map(([field, label]) => (
                  <div className="form-group" key={field}>
                    <label>
                      {label}
                      {!editing.isNew && (
                        <span style={{ color: 'var(--color-muted)', fontWeight: 400, fontSize: '0.8rem' }}>
                          {' '}(opcionális)
                        </span>
                      )}
                    </label>
                    <input
                      type="password"
                      value={editing[field]}
                      onChange={(e) => setEditing({ ...editing, [field]: e.target.value })}
                      placeholder={editing.isNew ? '' : 'Változatlan — töltsd ki a cseréhez'}
                      autoComplete="new-password"
                    />
                  </div>
                ))}
              </div>
              <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                <button className="btn btn-primary" onClick={handleSave} disabled={saving}>
                  {saving ? t('common.saving') : t('common.save')}
                </button>
                <button className="btn btn-secondary" onClick={() => setEditing(null)}>
                  {t('common.cancel')}
                </button>
              </div>
            </div>
          )}
        </>
      )}

      {/* ── Környezetváltó megerősítő modal ── */}
      {switchTarget && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
        }}>
          <div className="card" style={{ maxWidth: 480, width: '90%', margin: 0 }}>
            <h3 style={{ margin: '0 0 14px', fontSize: '1rem' }}>
              Beküldési környezet váltása — {NAV_LABELS[switchTarget]}
            </h3>

            {switchTarget === 'production' && (
              <div className="alert-error mb-4" style={{ fontWeight: 500 }}>
                ⚠ Ettől a ponttól a számlák a <strong>VALÓDI NAV éles rendszerbe</strong> kerülnek beküldésre.
              </div>
            )}

            {/* Figyelmeztető, ha a cél environmenthez nincs aktív credential */}
            {!navData.credentials.some((c) => c.environment === switchTarget && c.is_active) && (
              <div className="alert-error mb-4" style={{ fontSize: '0.875rem' }}>
                A(z) {NAV_LABELS[switchTarget]} környezethez nincs aktív hitelesítő beállítva
                — a váltás a szerver által el lesz utasítva.
              </div>
            )}

            {switchError && <div className="alert-error mb-4">{switchError}</div>}

            <p style={{ marginBottom: 16, fontSize: '0.9rem' }}>
              Biztosan átvált a <strong>{NAV_LABELS[switchTarget].toLowerCase()}</strong> beküldési környezetre?
            </p>
            <div style={{ display: 'flex', gap: 8 }}>
              <button className="btn btn-primary" onClick={confirmSwitch} disabled={switching}>
                {switching ? 'Váltás...' : `Igen, váltás ${NAV_LABELS[switchTarget].toLowerCase()}re`}
              </button>
              <button className="btn btn-secondary" onClick={() => setSwitchTarget(null)} disabled={switching}>
                {t('common.cancel')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

export default function CompanyPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const [form, setForm]       = useState(null)
  const [error, setError]     = useState('')
  const [success, setSuccess] = useState(false)
  const [saving, setSaving]   = useState(false)
  const [logoUrl, setLogoUrl] = useState(null)
  const [logoUploading, setLogoUploading] = useState(false)
  const logoInputRef   = useRef(null)
  const hasScrolled    = useRef(false)

  useEffect(() => {
    companyApi.get().then((res) => {
      setForm(res.data.data)
      setLogoUrl(res.data.data.logo_url ?? null)
    })
  }, [])

  // Scroll to hash-target after form data loads. The 400ms delay lets
  // section-level data (nav, simplepay) render before scrollIntoView fires,
  // so the destination element already has its full height.
  // hasScrolled guard prevents re-firing on every setField call.
  useEffect(() => {
    if (!form || hasScrolled.current) return
    const hash = window.location.hash
    if (!hash) return
    hasScrolled.current = true
    const timer = setTimeout(() => {
      document.getElementById(hash.slice(1))?.scrollIntoView({ behavior: 'smooth' })
    }, 400)
    return () => clearTimeout(timer)
  }, [form])

  function setField(k, v) { setForm((f) => ({ ...f, [k]: v })); setSuccess(false) }

  async function handleLogoUpload(e) {
    const file = e.target.files?.[0]
    if (!file) return
    setLogoUploading(true)
    try {
      const res = await companyApi.uploadLogo(file)
      setLogoUrl(res.data.logo_url)
    } catch (err) {
      setError(err.response?.data?.message ?? t('common.error'))
    } finally { setLogoUploading(false) }
  }

  async function handleLogoDelete() {
    setLogoUploading(true)
    try {
      await companyApi.deleteLogo()
      setLogoUrl(null)
    } finally { setLogoUploading(false) }
  }

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

  const basicFields = [
    ['name', t('company.name'), 'text', true],
    ['tax_number', t('company.tax_number'), 'text', true],
    ['eu_tax_number', t('company.eu_tax'), 'text', false],
    ['registration_number', t('company.reg_number'), 'text', true],
    ['email', t('common.email'), 'email', false],
    ['phone', t('company.phone'), 'text', false],
  ]

  const addressFields = [
    ['postal_code', t('company.postal'), 'text', true],
    ['city', t('company.city'), 'text', true],
    ['address_line', t('company.address'), 'text', true],
  ]

  return (
    <div>
      <div className="page-header"><h1 className="page-title">{t('company.title')}</h1></div>
      {error && <div className="alert-error mb-4">{error}</div>}
      {success && <div className="mb-4 alert-success">Mentve.</div>}
      {can('company.manage') && (
        <div className="card" style={{ marginBottom: 16, display: 'flex', alignItems: 'center', gap: 20 }}>
          {logoUrl ? (
            <img src={logoUrl} alt="logo" style={{ maxHeight: 64, maxWidth: 200, borderRadius: 4 }} />
          ) : (
            <div style={{ width: 120, height: 64, background: 'var(--color-surface-alt)', borderRadius: 4, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--color-muted)', fontSize: 11 }}>
              {t('company.no_logo')}
            </div>
          )}
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="btn btn-secondary btn-sm" onClick={() => logoInputRef.current?.click()} disabled={logoUploading}>
              {logoUrl ? t('company.change_logo') : t('company.upload_logo')}
            </button>
            {logoUrl && (
              <button type="button" className="btn btn-danger btn-sm" onClick={handleLogoDelete} disabled={logoUploading}>
                {t('common.delete')}
              </button>
            )}
            <input ref={logoInputRef} type="file" accept="image/jpeg,image/png,image/gif,image/webp" style={{ display: 'none' }} onChange={handleLogoUpload} />
          </div>
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="card">
          {basicFields.map(([key, label, type, req]) => (
            <div className="form-group" key={key}>
              <label>{label}</label>
              <input type={type} value={form[key] ?? ''} onChange={(e) => setField(key, e.target.value)} required={req} />
            </div>
          ))}
          <div className="form-group">
            <label>{t('company.base_currency')}</label>
            <select value={form.base_currency ?? 'HUF'} onChange={(e) => setField('base_currency', e.target.value)}>
              <option value="HUF">HUF</option>
              <option value="EUR">EUR</option>
              <option value="USD">USD</option>
            </select>
          </div>
        </div>

        <div className="card">
          <h2 style={{ margin: '0 0 14px', fontSize: '1.1rem' }}>{t('company.address_section')}</h2>
          {addressFields.map(([key, label, type, req]) => (
            <div className="form-group" key={key}>
              <label>{label}</label>
              <input type={type} value={form[key] ?? ''} onChange={(e) => setField(key, e.target.value)} required={req} />
            </div>
          ))}
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
      {can('company.manage') && <AccentColorSection can={can} />}
      {can('company.manage') && <div id="section-simplepay"><SimplePaySection can={can} /></div>}
      {/* Értékesítő csoport prefix: can('sales_group.view') a modul-proxy — modul ki = false, be = true */}
      {can('sales_group.view') && <SalesGroupPrefixSection can={can} />}
      {/* NAV szekció: can('invoice.send_nav') a modul-proxy — NAV modul ki = false, be = true */}
      {can('invoice.send_nav') && <div id="section-nav"><NavSection can={can} /></div>}
    </div>
  )
}
