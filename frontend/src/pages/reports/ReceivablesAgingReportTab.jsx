import { useEffect, useState } from 'react'
import { reports } from '../../api/reports'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { formatCurrency } from '../../utils/format'
import { todayStr } from '../../utils/reportPeriods'
import { useUrlFilters } from '../../utils/useUrlFilters'
import ReportFilterCard from '../../components/reports/ReportFilterCard'

const DEFAULTS = { as_of: todayStr(), partner_id: '' }

const BANDS = [
  { key: 'not_due', labelKey: 'reports.band_not_due', severity: '' },
  { key: 'band_0_30', labelKey: 'reports.band_0_30', severity: 'aging--warn' },
  { key: 'band_31_60', labelKey: 'reports.band_31_60', severity: 'aging--warn-strong' },
  { key: 'band_61_90', labelKey: 'reports.band_61_90', severity: 'aging--danger' },
  { key: 'band_90_plus', labelKey: 'reports.band_90_plus', severity: 'aging--danger-strong' },
]

/**
 * A kintlévőség fül szűrősávja MÁS, mint a többié — csak egy "állapot
 * dátuma" (as_of) + a partner-szűrő popover van, nincs tartomány/dátum-alap/
 * gyorsválasztó (l. ReportFilterCard variant="aging").
 */
export default function ReceivablesAgingReportTab({ activeTab, onTabChange }) {
  const { t, locale } = useTranslation()
  const toast = useToast()
  const [filters, setFilters] = useUrlFilters(DEFAULTS)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [lastUpdatedAt, setLastUpdatedAt] = useState(null)

  async function load() {
    setLoading(true)
    setError(false)
    try {
      const res = await reports.receivablesAging({ as_of: filters.as_of, partner_id: filters.partner_id || undefined })
      setData(res.data)
      setLastUpdatedAt(new Date())
    } catch (err) {
      setError(true)
      if (err.response?.status !== 403) {
        toast(err.response?.data?.message ?? t('reports.load_error'), 'error')
      }
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [filters.as_of, filters.partner_id]) // eslint-disable-line react-hooks/exhaustive-deps

  const cardFilters = { as_of: filters.as_of, partner_id: filters.partner_id || null }

  function handleCardFiltersChange(patch) {
    const urlPatch = { ...patch }
    if ('partner_id' in patch) urlPatch.partner_id = patch.partner_id ?? ''
    setFilters(urlPatch)
  }

  const isEmpty = data && data.partners.length === 0

  return (
    <div>
      <ReportFilterCard
        variant="aging"
        activeTab={activeTab}
        onTabChange={onTabChange}
        filters={cardFilters}
        onFiltersChange={handleCardFiltersChange}
        exportReport="receivables-aging"
        exportFilters={{ as_of: filters.as_of, partner_id: filters.partner_id || undefined }}
        resultLabel={data ? t('reports.count_partners', { count: data.partners.length }) : null}
        lastUpdatedAt={lastUpdatedAt}
      />

      {loading && !data ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : error && !data ? (
        <div>
          <p className="alert-error">{t('reports.load_error')}</p>
          <button className="btn btn-secondary" onClick={load}>{t('common.retry')}</button>
        </div>
      ) : (
        <>
          {data.warnings.skipped_count > 0 && (
            <p className="alert-warning">{t('reports.warning_skipped', { count: data.warnings.skipped_count })}</p>
          )}

          {isEmpty ? (
            <p className="text-muted">{t('reports.empty')}</p>
          ) : (
            <>
              <div className="dashboard-kpis">
                {BANDS.map((band) => (
                  <div key={band.key} className={`card kpi-card aging-card ${band.severity}`}>
                    <div className="kpi-label">{t(band.labelKey)}</div>
                    <div className="kpi-value">{formatCurrency(data.totals[band.key], 'HUF', locale)}</div>
                  </div>
                ))}
              </div>

              <table>
                <thead>
                  <tr>
                    <th>{t('reports.col_partner')}</th>
                    {BANDS.map((band) => <th key={band.key} className="text-right">{t(band.labelKey)}</th>)}
                    <th className="text-right">{t('reports.col_total')}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.partners.map((row) => (
                    <tr key={row.partner_id}>
                      <td>{row.partner_name ?? '—'}</td>
                      {BANDS.map((band) => (
                        <td key={band.key} className={`text-right ${band.severity}`}>
                          {formatCurrency(row[band.key], 'HUF', locale)}
                        </td>
                      ))}
                      <td className="text-right"><strong>{formatCurrency(row.total, 'HUF', locale)}</strong></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          )}
        </>
      )}
    </div>
  )
}
