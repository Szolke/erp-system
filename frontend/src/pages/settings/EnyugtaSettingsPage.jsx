import { useEffect, useState } from 'react'
import { useAuth } from '../../contexts/AuthContext'
import { useToast } from '../../contexts/ToastContext'
import { enyugtaSettings } from '../../api/enyugta'

const MODE_LABELS = { mock: 'Teszt (mock, NAV-kapcsolat nélkül)', test: 'NAV teszt', live: 'Éles' }

const SECRET_FIELDS = [
  ['login', 'Bejelentkezési név'],
  ['password', 'Jelszó'],
  ['signing_key', 'Aláírókulcs'],
  ['exchange_key', 'Cserekulcs'],
]

function emptyForm(data) {
  return {
    login: '', password: '', signing_key: '', exchange_key: '',
    tax_number: data.tax_number ?? '',
    mode: data.mode ?? 'mock',
    base_url_override: data.base_url_override ?? '',
    send_empty_reports: data.send_empty_reports ?? false,
  }
}

/**
 * NAV eNyugta beállítások — 4. fázis frontend. A titkos mezőket a backend
 * soha nem adja vissza (csak has_* boolt) — üresen hagyva a mentés megtartja
 * a tárolt (titkosított) értéket, a CompanyPage NavSection mintáját követve.
 */
export default function EnyugtaSettingsPage() {
  const { can } = useAuth()
  const toast = useToast()
  const canManage = can('enyugta.manage')

  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(null) // null | 'forbidden' | 'generic'
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(null)
  const [formError, setFormError] = useState('')
  const [copyConfirm, setCopyConfirm] = useState(false)
  const [copying, setCopying] = useState(false)
  const [copyError, setCopyError] = useState('')

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function load() {
    setLoading(true)
    setLoadError(null)
    try {
      const res = await enyugtaSettings.get()
      setData(res.data.data)
      setForm(emptyForm(res.data.data))
    } catch (err) {
      setLoadError(err.response?.status === 403 ? 'forbidden' : 'generic')
    } finally {
      setLoading(false)
    }
  }

  async function handleSave(e) {
    e.preventDefault()
    setSaving(true)
    setFormError('')
    try {
      const payload = {
        tax_number: form.tax_number,
        mode: form.mode,
        base_url_override: form.base_url_override || null,
        send_empty_reports: form.send_empty_reports,
      }
      // Csak a ténylegesen kitöltött titkos mezők mennek — üresen hagyva a
      // backend megtartja a tárolt (titkosított) értéket.
      SECRET_FIELDS.forEach(([field]) => { if (form[field]) payload[field] = form[field] })

      await enyugtaSettings.update(payload)
      toast('Beállítások mentve.', 'success')
      await load()
    } catch (err) {
      const errs = err.response?.data?.errors
      setFormError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? 'Hiba mentés közben.')
    } finally {
      setSaving(false)
    }
  }

  async function handleCopyFromNav() {
    setCopying(true)
    setCopyError('')
    try {
      await enyugtaSettings.copyFromNav({})
      setCopyConfirm(false)
      toast('Hitelesítő adatok átmásolva az Online Számla beállításokból.', 'success')
      await load()
    } catch (err) {
      setCopyError(err.response?.data?.message ?? 'Hiba az átmásolás közben.')
    } finally {
      setCopying(false)
    }
  }

  if (loading) return <p className="text-muted">Betöltés…</p>

  if (loadError || !form) {
    return (
      <div>
        <div className="page-header">
          <h1 className="page-title">NAV eNyugta beállítások</h1>
        </div>
        <div className="doc-state-message">
          <p className="alert-error">
            {loadError === 'forbidden'
              ? 'Nincs jogosultságod ennek az oldalnak a megtekintéséhez.'
              : 'Hiba a beállítások betöltése közben.'}
          </p>
          {loadError !== 'forbidden' && (
            <button className="btn btn-secondary" onClick={load}>Újrapróbálom</button>
          )}
        </div>
      </div>
    )
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">NAV eNyugta beállítások</h1>
      </div>

      <div className="alert-warning">
        ⚠ A tényleges NAV-beküldés jelenleg NEM érhető el — a NAV eddig nem publikált nyugtaadat-szolgáltatási
        bázis-URL-t (sem teszt, sem éles környezetre). Addig a napi jelentések CSV-ben exportálhatók a{' '}
        <strong>Jelentések</strong> oldalon, és kézzel rögzíthetők a NAV KOBAK-portálján.
      </div>

      <form onSubmit={handleSave} className="card">
        {formError && <div className="alert-error">{formError}</div>}

        <div className="form-group">
          <label>Adószám (8 jegyű törzsszám)</label>
          <input
            value={form.tax_number}
            onChange={(e) => setForm({ ...form, tax_number: e.target.value })}
            placeholder="12345678"
            maxLength={8}
            disabled={!canManage}
            required
          />
        </div>

        <div className="form-group">
          <label>Üzemmód</label>
          <select
            value={form.mode}
            onChange={(e) => setForm({ ...form, mode: e.target.value })}
            disabled={!canManage}
          >
            <option value="mock">{MODE_LABELS.mock}</option>
            <option value="test">{MODE_LABELS.test}</option>
            <option value="live">{MODE_LABELS.live}</option>
          </select>
          {form.mode === 'live' && (
            <p style={{ fontSize: 12, color: 'var(--color-warning)', marginTop: 4, marginBottom: 0 }}>
              ⚠ Éles mód kiválasztva — a tényleges beküldés ennek ellenére NEM működik, amíg a NAV nem
              publikál bázis-URL-t (l. fenti figyelmeztetés).
            </p>
          )}
        </div>

        <div className="form-group">
          <label>Bázis URL felülbírálás (opcionális)</label>
          <input
            value={form.base_url_override}
            onChange={(e) => setForm({ ...form, base_url_override: e.target.value })}
            placeholder="https://…"
            disabled={!canManage}
          />
        </div>

        <div className="form-group" style={{ width: 'auto' }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <input
              type="checkbox"
              checked={form.send_empty_reports}
              onChange={(e) => setForm({ ...form, send_empty_reports: e.target.checked })}
              disabled={!canManage}
              style={{ width: 'auto' }}
            />
            Nullás napok (0 nyugta) jelentésének beküldése is
          </label>
          <p className="text-muted" style={{ fontSize: 12, margin: '4px 0 0' }}>
            A tényleges beküldés amúgy is csak a 3. fázistól elérhető — ez a kapcsoló azt szabályozza majd, kell-e nullás napra is beküldeni.
          </p>
        </div>

        <hr style={{ margin: '20px 0', border: 'none', borderTop: '1px solid var(--color-border)' }} />

        <h3 style={{ fontSize: '0.95rem', margin: '0 0 4px' }}>Technikai felhasználó</h3>
        <p className="text-muted" style={{ fontSize: 13, marginBottom: 14 }}>
          A már beállított mezők üresen hagyva megtartják a jelenlegi (titkosítva tárolt) értéküket.
        </p>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          {SECRET_FIELDS.map(([field, label]) => (
            <div className="form-group" key={field}>
              <label>
                {label}{' '}
                {data[`has_${field}`]
                  ? <span style={{ color: 'var(--color-success-text)', fontWeight: 600, fontSize: 12 }}>✓ Beállítva</span>
                  : <span style={{ color: 'var(--color-muted)', fontSize: 12 }}>— Nincs beállítva</span>}
              </label>
              <input
                type={field === 'password' ? 'password' : 'text'}
                value={form[field]}
                onChange={(e) => setForm({ ...form, [field]: e.target.value })}
                placeholder={data[`has_${field}`] ? 'Változatlan' : ''}
                disabled={!canManage}
                autoComplete="off"
              />
            </div>
          ))}
        </div>

        {canManage && (
          <div style={{ display: 'flex', gap: 8, marginTop: 20 }}>
            <button className="btn btn-primary" type="submit" disabled={saving}>
              {saving ? 'Mentés…' : 'Mentés'}
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => { setCopyError(''); setCopyConfirm(true) }}
            >
              Átmásolás az Online Számla beállításokból
            </button>
          </div>
        )}
      </form>

      {/* ── Átmásolás megerősítő modal (D2) ── */}
      {copyConfirm && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
        }}>
          <div className="card" style={{ maxWidth: 480, width: '90%', margin: 0 }}>
            <h3 style={{ margin: '0 0 14px', fontSize: '1rem' }}>Átmásolás az Online Számla beállításokból</h3>
            <div className="alert-error">
              ⚠ Ez felülírja a fent beállított technikai felhasználó adatait (bejelentkezési név, jelszó,
              aláírókulcs, cserekulcs, adószám). Nem garantált, hogy ugyanaz a technikai felhasználó
              ténylegesen működik az eNyugta interfészen is.
            </div>
            {copyError && <div className="alert-error">{copyError}</div>}
            <p style={{ margin: '0 0 16px', fontSize: '0.9rem' }}>Biztosan folytatod?</p>
            <div style={{ display: 'flex', gap: 8 }}>
              <button className="btn btn-primary" onClick={handleCopyFromNav} disabled={copying}>
                {copying ? 'Másolás…' : 'Igen, átmásolás'}
              </button>
              <button className="btn btn-secondary" onClick={() => setCopyConfirm(false)} disabled={copying}>
                Mégsem
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
