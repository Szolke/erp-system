import { useEffect, useState } from 'react'
import { documentSeries as dsApi } from '../../api/documentSeries'
import { useTranslation } from '../../contexts/TranslationContext'

const TYPE_ORDER = ['invoice', 'invoice_storno', 'receipt', 'receipt_storno']

function ym() {
  const d = new Date()
  return `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}`
}

function preview(prefix, nextNumber) {
  if (!prefix) return '—'
  return `${prefix}-${ym()}-${String(nextNumber ?? 1).padStart(6, '0')}`
}

function SeriesRow({ series, onSaved, t }) {
  const [prefix, setPrefix]           = useState(series.prefix)
  const [resetYearly, setResetYearly] = useState(series.reset_yearly)
  const [saving, setSaving]           = useState(false)
  const [error, setError]             = useState('')
  const [saved, setSaved]             = useState(false)

  const dirty = prefix !== series.prefix || resetYearly !== series.reset_yearly

  async function handleSave() {
    setSaving(true)
    setError('')
    setSaved(false)
    try {
      const res = await dsApi.update(series.id, { prefix, reset_yearly: resetYearly })
      onSaved(res.data)
      setSaved(true)
      setTimeout(() => setSaved(false), 2500)
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? 'Hiba')
    } finally { setSaving(false) }
  }

  return (
    <tr>
      <td style={{ fontWeight: 600, width: 160 }}>{series.label}</td>
      <td>
        <input
          value={prefix}
          onChange={(e) => { setPrefix(e.target.value.toUpperCase()); setSaved(false) }}
          placeholder="pl. SZ"
          style={{ width: 110, textTransform: 'uppercase' }}
          maxLength={20}
        />
        {error && <div style={{ color: '#b91c1c', fontSize: 11, marginTop: 3 }}>{error}</div>}
      </td>
      <td>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
          <input
            type="checkbox"
            checked={resetYearly}
            onChange={(e) => { setResetYearly(e.target.checked); setSaved(false) }}
            style={{ width: 'auto' }}
          />
          <span style={{ fontSize: 13 }}>Évente nullázódik</span>
        </label>
      </td>
      <td style={{ color: 'var(--color-muted)', fontSize: 13, fontFamily: 'monospace' }}>
        {preview(prefix, series.next_number)}
      </td>
      <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
        {saved && <span style={{ color: '#15803d', fontSize: 12, marginRight: 10 }}>✓ {t('common.save')}</span>}
        <button
          className="btn btn-primary btn-sm"
          onClick={handleSave}
          disabled={saving || !dirty || !prefix}
        >
          {saving ? t('common.saving') : t('common.save')}
        </button>
      </td>
    </tr>
  )
}

export default function DocumentSeriesSettingsPage() {
  const { t } = useTranslation()
  const [list, setList]       = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState('')

  async function load() {
    try {
      const res = await dsApi.list()
      const data = res.data.data ?? []
      // Meghatározott sorrendben rendezzük
      data.sort((a, b) => TYPE_ORDER.indexOf(a.document_type) - TYPE_ORDER.indexOf(b.document_type))
      setList(data)
    } catch (err) {
      setError(err.response?.data?.message ?? 'Nem sikerült betölteni a sorszámtartományokat.')
    } finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  function handleSaved(updated) {
    setList((prev) => prev.map((s) => s.document_type === updated.document_type ? updated : s))
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('docseries.title')}</h1>
      </div>

      <div className="card">
        <p className="text-muted" style={{ fontSize: 13, marginBottom: 16 }}>
          A prefix csak nagybetűt és számot tartalmazhat. A sorszámformátum:{' '}
          <span style={{ fontFamily: 'monospace' }}>PREFIX-ÉÉÉÉHH-000001</span>
        </p>

        {error && <div className="alert-error mb-4">{error}</div>}

        {loading ? (
          <p className="text-muted">{t('common.loading')}</p>
        ) : (
          <table>
            <thead>
              <tr>
                <th>{t('docseries.type')}</th>
                <th>{t('docseries.prefix')}</th>
                <th>{t('docseries.year')}</th>
                <th>{t('docseries.next_number')}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {list.map((series) =>
                series.exists ? (
                  <SeriesRow key={series.document_type} series={series} onSaved={handleSaved} t={t} />
                ) : (
                  <tr key={series.document_type}>
                    <td style={{ fontWeight: 600 }}>{series.label}</td>
                    <td colSpan={4} className="text-muted" style={{ fontSize: 13 }}>
                      Még nem jött létre — az első bizonylat kiállításakor automatikusan létrejön.
                    </td>
                  </tr>
                )
              )}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
