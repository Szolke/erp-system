import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { CornerDownRight } from 'lucide-react'
import { enyugtaReports } from '../api/enyugta'
import { documents as documentsApi } from '../api/documents'
import { useTranslation } from '../contexts/TranslationContext'
import { EnyugtaReportStatusBadge, EnyugtaReportTypeBadge } from '../components/StatusBadge'
import DateRangePicker from '../components/reports/DateRangePicker'
import { useUrlFilters } from '../utils/useUrlFilters'
import { formatCurrency } from '../utils/format'

const STATUS_OPTIONS = [
  ['', 'Összes állapot'],
  ['draft', 'Piszkozat'],
  ['ready', 'Kész'],
  ['sending', 'Beküldés alatt'],
  ['accepted', 'Elfogadva'],
  ['rejected', 'Elutasítva'],
]

const DEFAULTS = { date_from: '', date_to: '', status: '' }

/**
 * NAV eNyugta napi jelentések listája (4. fázis). A GET /api/enyugta/reports
 * nem lapoz (sima Resource::collection, nincs paginate()) — a lista tehát a
 * TELJES szűrt halmazt adja vissza egyszerre, nincs Pagination-komponens.
 */
export default function EnyugtaReportsPage() {
  const { locale } = useTranslation()
  const [filters, setFilters] = useUrlFilters(DEFAULTS)
  const [reports, setReports] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null) // null | 'forbidden' | 'generic'
  const [openPopover, setOpenPopover] = useState(false)

  // D4: diszkrét tájékoztató, ha az elmúlt 30 napban a cégnek egy nyugtája
  // sincs — a meglévő GET /api/documents (type=receipt) végpontból
  // származtatva, nincs hozzá új backend-végpont.
  const [noRecentReceipts, setNoRecentReceipts] = useState(false)

  useEffect(() => { load() }, [filters.date_from, filters.date_to, filters.status]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { checkRecentReceipts() }, [])

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const res = await enyugtaReports.list({
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
        status: filters.status || undefined,
      })
      setReports(res.data.data)
    } catch (err) {
      setError(err.response?.status === 403 ? 'forbidden' : 'generic')
    } finally {
      setLoading(false)
    }
  }

  async function checkRecentReceipts() {
    try {
      const since = new Date()
      since.setDate(since.getDate() - 30)
      const res = await documentsApi.list({
        type: 'receipt',
        date_from: since.toISOString().slice(0, 10),
        per_page: 1,
      })
      setNoRecentReceipts((res.data.summary?.count ?? 0) === 0)
    } catch {
      // Nem kritikus tájékoztató sáv — csendben kimarad, ha a lekérdezés hibázik.
    }
  }

  function handleDateRangeApply(dateFrom, dateTo) {
    setFilters({ date_from: dateFrom, date_to: dateTo })
  }

  const rows = reports ?? []
  const reportsById = Object.fromEntries(rows.map((r) => [r.id, r]))

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">NAV eNyugta — Jelentések</h1>
      </div>

      {noRecentReceipts && (
        <div className="alert-info">
          Az elmúlt 30 napban nem volt nyugta kiállítva ennél a cégnél — valószínűleg nincs szükség a NAV
          eNyugta modulra, mert a nyugtaadat-szolgáltatási kötelezettség csak a nyugtát kiállító
          vállalkozásokat érinti.
        </div>
      )}

      <div className="card">
        <div className="search-row" style={{ flexWrap: 'wrap', gap: 10 }}>
          <select
            value={filters.status}
            onChange={(e) => setFilters({ status: e.target.value })}
            style={{ width: 'auto' }}
          >
            {STATUS_OPTIONS.map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>

          <DateRangePicker
            unit="day"
            align="left"
            from={filters.date_from}
            to={filters.date_to}
            onApply={handleDateRangeApply}
            open={openPopover}
            onOpenChange={setOpenPopover}
            id="enyugta-reports-daterange"
          />
        </div>

        <div className="doc-table-wrap mt-4">
          {loading ? (
            <p className="text-muted doc-state-message">Betöltés…</p>
          ) : error ? (
            <div className="doc-state-message">
              <p className="alert-error">
                {error === 'forbidden'
                  ? 'Nincs jogosultságod a jelentések megtekintéséhez.'
                  : 'Hiba a jelentések betöltése közben.'}
              </p>
              {error !== 'forbidden' && (
                <button className="btn btn-secondary" onClick={load}>Újrapróbálom</button>
              )}
            </div>
          ) : rows.length === 0 ? (
            <p className="doc-state-message text-muted">Nincs a szűrésnek megfelelő jelentés.</p>
          ) : (
            <table className="doc-table">
              <thead>
                <tr>
                  <th scope="col">Nap</th>
                  <th scope="col">Típus</th>
                  <th scope="col">Állapot</th>
                  <th scope="col">Nyugtaszám</th>
                  <th scope="col">Bruttó összeg</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((report) => {
                  const original = report.original_report_id ? reportsById[report.original_report_id] : null
                  return (
                    <tr key={report.id}>
                      <td>
                        <Link to={`/enyugta/reports/${report.id}`} className="table-link">
                          {report.report_date}
                        </Link>
                      </td>
                      <td>
                        <EnyugtaReportTypeBadge type={report.type} />
                        {report.type === 'correction' && (
                          <div className="text-muted" style={{ fontSize: 11, display: 'flex', alignItems: 'center', gap: 3, marginTop: 3 }}>
                            <CornerDownRight size={11} aria-hidden="true" />
                            {original
                              ? <Link to={`/enyugta/reports/${original.id}`} className="table-link">eredeti: #{original.id}</Link>
                              : <span>eredeti: #{report.original_report_id}</span>}
                          </div>
                        )}
                      </td>
                      <td><EnyugtaReportStatusBadge status={report.status} /></td>
                      <td>{report.receipt_count}</td>
                      <td>{formatCurrency(report.total_gross, 'Ft', locale)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          )}
        </div>

        {reports && rows.length > 0 && (
          <div className="doc-summary mt-4">
            <span>Összesen: {rows.length} jelentés</span>
          </div>
        )}
      </div>
    </div>
  )
}
