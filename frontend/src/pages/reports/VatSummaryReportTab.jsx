import { useEffect, useState } from 'react'
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts'
import { reports } from '../../api/reports'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { formatCurrency } from '../../utils/format'
import { thisYearRange } from '../../utils/reportPeriods'
import { useUrlFilters } from '../../utils/useUrlFilters'
import ReportFilterCard from '../../components/reports/ReportFilterCard'

const DEFAULTS = { ...thisYearRange(), date_basis: 'fulfillment' }

/**
 * A könyvelőnek átadható nézet — itt a TÁBLÁZAT a fő tartalom, a
 * kategóriánkénti megoszlás-diagram csak másodlagos, kisebb és a táblázat
 * ALATT jelenik meg (nem a hangsúlyos elem), ahogy a feladat is kéri.
 */
export default function VatSummaryReportTab({ activeTab, onTabChange }) {
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
      const res = await reports.vatSummary({ from: filters.from, to: filters.to, date_basis: filters.date_basis })
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

  useEffect(() => { load() }, [filters.from, filters.to, filters.date_basis]) // eslint-disable-line react-hooks/exhaustive-deps

  const isEmpty = data && data.items.length === 0

  return (
    <div>
      <ReportFilterCard
        variant="vat"
        activeTab={activeTab}
        onTabChange={onTabChange}
        filters={{ from: filters.from, to: filters.to, date_basis: filters.date_basis }}
        onFiltersChange={setFilters}
        exportReport="vat-summary"
        exportFilters={{ from: filters.from, to: filters.to, date_basis: filters.date_basis }}
        resultLabel={data ? t('reports.count_rows', { count: data.items.length }) : null}
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
              <table>
                <thead>
                  <tr>
                    <th>{t('reports.col_period')}</th>
                    <th>{t('reports.col_vat_category')}</th>
                    <th className="text-right">{t('reports.col_net_base')}</th>
                    <th className="text-right">{t('reports.col_vat')}</th>
                    <th className="text-right">{t('reports.col_gross')}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((row, i) => (
                    <tr key={`${row.period}-${row.vat_rate_id}-${i}`}>
                      <td>{row.period}</td>
                      <td>{row.vat_category}{row.nav_code ? ` (${row.nav_code})` : ''}</td>
                      <td className="text-right">{formatCurrency(row.net_total, 'HUF', locale)}</td>
                      <td className="text-right">{formatCurrency(row.vat_total, 'HUF', locale)}</td>
                      <td className="text-right">{formatCurrency(row.gross_total, 'HUF', locale)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  {data.totals.by_category.map((cat) => (
                    <tr key={cat.vat_rate_id} className="total-row">
                      <td colSpan={2}>{t('common.total')}: {cat.vat_category}{cat.nav_code ? ` (${cat.nav_code})` : ''}</td>
                      <td className="text-right">{formatCurrency(cat.net_total, 'HUF', locale)}</td>
                      <td className="text-right">{formatCurrency(cat.vat_total, 'HUF', locale)}</td>
                      <td className="text-right">{formatCurrency(cat.gross_total, 'HUF', locale)}</td>
                    </tr>
                  ))}
                  <tr className="total-row report-grand-total-row">
                    <td colSpan={2}>{t('reports.row_total')}</td>
                    <td className="text-right">{formatCurrency(data.totals.grand_total.net_total, 'HUF', locale)}</td>
                    <td className="text-right">{formatCurrency(data.totals.grand_total.vat_total, 'HUF', locale)}</td>
                    <td className="text-right">{formatCurrency(data.totals.grand_total.gross_total, 'HUF', locale)}</td>
                  </tr>
                </tfoot>
              </table>

              {data.totals.by_category.length > 1 && (
                <div className="card report-secondary-chart">
                  <h2 className="report-chart-title">{t('reports.chart_vat_distribution')}</h2>
                  <ResponsiveContainer width="100%" height={Math.max(140, data.totals.by_category.length * 32)}>
                    <BarChart data={data.totals.by_category} layout="vertical" margin={{ left: 24 }}>
                      <CartesianGrid strokeDasharray="3 3" />
                      <XAxis type="number" fontSize={11} />
                      <YAxis type="category" dataKey="vat_category" width={140} fontSize={11} />
                      <Tooltip
                        formatter={(value) => formatCurrency(value, 'HUF', locale)}
                        contentStyle={{ backgroundColor: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 6 }}
                        labelStyle={{ color: 'var(--color-text)' }}
                      />
                      <Bar dataKey="net_total" name={t('reports.col_net_base')} fill="var(--color-primary)" />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}
            </>
          )}
        </>
      )}
    </div>
  )
}
