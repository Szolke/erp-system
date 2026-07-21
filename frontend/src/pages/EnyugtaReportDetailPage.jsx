import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { enyugtaReports } from '../api/enyugta'
import { useTranslation } from '../contexts/TranslationContext'
import { EnyugtaReportStatusBadge, EnyugtaReportTypeBadge } from '../components/StatusBadge'
import ExportButton from '../components/ExportButton'
import { formatCurrency } from '../utils/format'

/**
 * Egy napi NAV eNyugta jelentés részletnézete (4. fázis) — áfakategóriánkénti
 * sorok + összesítő + CSV letöltés. A CSV a NAV KOBAK-portálján való kézi
 * rögzítéshez való (D7) — a tényleges gépi beküldés a 3. fázis feladata,
 * ami a NAV publikált bázis-URL-jére vár.
 */
export default function EnyugtaReportDetailPage() {
  const { id } = useParams()
  const { locale } = useTranslation()
  const [report, setReport] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null) // null | 'forbidden' | 'not_found' | 'generic'

  useEffect(() => {
    setLoading(true)
    setError(null)
    enyugtaReports.get(id)
      .then((res) => setReport(res.data.data))
      .catch((err) => {
        const status = err.response?.status
        setError(status === 403 ? 'forbidden' : status === 404 ? 'not_found' : 'generic')
      })
      .finally(() => setLoading(false))
  }, [id])

  if (loading) return <p className="text-muted">Betöltés…</p>
  if (error || !report) {
    const message = {
      forbidden: 'Nincs jogosultságod ennek a jelentésnek a megtekintéséhez.',
      not_found: 'A jelentés nem található.',
      generic: 'Hiba a jelentés betöltése közben.',
    }[error] ?? 'Hiba a jelentés betöltése közben.'

    return (
      <div>
        <p className="alert-error">{message}</p>
        <Link to="/enyugta/reports" className="btn btn-secondary">← Vissza a jelentésekhez</Link>
      </div>
    )
  }

  const lines = report.lines ?? []

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">NAV eNyugta jelentés — {report.report_date}</h1>
          <div className="flex mt-4" style={{ gap: 6 }}>
            <EnyugtaReportTypeBadge type={report.type} />
            <EnyugtaReportStatusBadge status={report.status} />
          </div>
        </div>
        <div className="flex">
          <ExportButton
            visible
            onExport={() => enyugtaReports.export(report.id)}
            filename={`enyugta-jelentes-${report.report_date}-${report.id}.csv`}
            label="CSV letöltése"
            exportingLabel="Letöltés…"
            errorLabel="Hiba a letöltés közben."
          />
          <Link to="/enyugta/reports" className="btn btn-secondary">← Vissza</Link>
        </div>
      </div>

      <div className="alert-info">
        A CSV letöltése a NAV KOBAK-portálján való kézi nyugtaadat-rögzítéshez készült — addig, amíg a
        gépi (automatikus) beküldés nem érhető el (a NAV még nem publikált bázis-URL-t).
      </div>

      {report.type === 'correction' && report.original_report_id && (
        <div className="alert-warning">
          Ez a jelentés egy korábbi, elfogadott normál jelentés <strong>korrekciója</strong> — a nap teljes,
          újraszámolt összesítését tartalmazza.{' '}
          <Link to={`/enyugta/reports/${report.original_report_id}`} style={{ color: 'inherit', textDecoration: 'underline' }}>
            Eredeti jelentés megtekintése →
          </Link>
        </div>
      )}

      <div className="card">
        <div className="detail-section">
          <div className="detail-section-left">
            <div className="info-row">
              <span className="info-label">Nyugtaszám</span>
              <span className="info-value">{report.receipt_count}</span>
            </div>
            <div className="info-row">
              <span className="info-label">Legenerálva</span>
              <span className="info-value">{report.generated_at ?? '—'}</span>
            </div>
          </div>
          <div className="detail-section-right">
            <div className="info-row">
              <span className="info-label">Nettó</span>
              <span className="info-value">{formatCurrency(report.total_net, 'Ft', locale)}</span>
            </div>
            <div className="info-row">
              <span className="info-label">ÁFA</span>
              <span className="info-value">{formatCurrency(report.total_vat, 'Ft', locale)}</span>
            </div>
            <div className="info-row info-row--total">
              <span className="info-label">Bruttó</span>
              <span className="info-value">{formatCurrency(report.total_gross, 'Ft', locale)}</span>
            </div>
          </div>
        </div>

        <table className="items-table">
          <thead>
            <tr>
              <th>ÁFA-kategória</th>
              <th>Nettó</th>
              <th>ÁFA</th>
              <th>Bruttó</th>
              <th>Nyugtaszám</th>
            </tr>
          </thead>
          <tbody>
            {lines.length === 0 && (
              <tr>
                <td colSpan={5} className="text-muted">
                  Nincs sor ezen a napon — nullás nap (0 nyugta).
                </td>
              </tr>
            )}
            {lines.map((line) => (
              <tr key={line.id}>
                <td>{line.nav_receipt_category}</td>
                <td className="text-right">{formatCurrency(line.net_amount, null, locale)}</td>
                <td className="text-right">{formatCurrency(line.vat_amount, null, locale)}</td>
                <td className="text-right">{formatCurrency(line.gross_amount, null, locale)}</td>
                <td className="text-right">{line.receipt_count}</td>
              </tr>
            ))}
            {lines.length > 0 && (
              <tr className="total-row">
                <td>Összesen</td>
                <td className="text-right">{formatCurrency(report.total_net, null, locale)}</td>
                <td className="text-right">{formatCurrency(report.total_vat, null, locale)}</td>
                <td className="text-right">{formatCurrency(report.total_gross, null, locale)}</td>
                <td className="text-right">{report.receipt_count}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
