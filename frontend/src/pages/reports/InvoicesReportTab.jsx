import { useEffect, useState } from 'react'
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts'
import { reports } from '../../api/reports'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { formatCurrency } from '../../utils/format'
import { thisYearRange } from '../../utils/reportPeriods'
import { useUrlFilters } from '../../utils/useUrlFilters'
import ReportFilterCard from '../../components/reports/ReportFilterCard'

const DEFAULTS = {
  ...thisYearRange(), granularity: 'month', date_basis: 'fulfillment',
  include_receipts: '0', status: '', partner_id: '',
}

export default function InvoicesReportTab({ activeTab, onTabChange }) {
  const { t, locale } = useTranslation()
  const toast = useToast()
  const [filters, setFilters] = useUrlFilters(DEFAULTS)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [lastUpdatedAt, setLastUpdatedAt] = useState(null)

  const includeReceipts = filters.include_receipts === '1'

  async function load() {
    setLoading(true)
    setError(false)
    try {
      const res = await reports.invoices({
        from: filters.from,
        to: filters.to,
        granularity: filters.granularity,
        date_basis: filters.date_basis,
        include_receipts: includeReceipts ? 1 : undefined,
        status: filters.status || undefined,
        partner_id: filters.partner_id || undefined,
      })
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

  useEffect(() => { load() }, [filters.from, filters.to, filters.granularity, filters.date_basis, filters.include_receipts, filters.status, filters.partner_id]) // eslint-disable-line react-hooks/exhaustive-deps

  // A URL-állapot (useUrlFilters) mindent stringként tárol — a ReportFilterCard
  // viszont valódi bool/nullable típusokat vár a checkbox/select/chipek miatt.
  const cardFilters = {
    from: filters.from,
    to: filters.to,
    granularity: filters.granularity,
    date_basis: filters.date_basis,
    include_receipts: includeReceipts,
    status: filters.status || null,
    partner_id: filters.partner_id || null,
  }

  function handleCardFiltersChange(patch) {
    const urlPatch = { ...patch }
    if ('include_receipts' in patch) urlPatch.include_receipts = patch.include_receipts ? '1' : '0'
    if ('status' in patch) urlPatch.status = patch.status ?? ''
    if ('partner_id' in patch) urlPatch.partner_id = patch.partner_id ?? ''
    setFilters(urlPatch)
  }

  const isEmpty = data && data.totals.invoice_count === 0 && (!includeReceipts || !data.totals.receipt_count)

  return (
    <div>
      <ReportFilterCard
        variant="invoices"
        activeTab={activeTab}
        onTabChange={onTabChange}
        filters={cardFilters}
        onFiltersChange={handleCardFiltersChange}
        exportReport="invoices"
        exportFilters={{
          from: filters.from, to: filters.to, granularity: filters.granularity, date_basis: filters.date_basis,
          include_receipts: includeReceipts ? 1 : undefined, status: filters.status || undefined, partner_id: filters.partner_id || undefined,
        }}
        resultLabel={data ? t('reports.count_invoices', { count: data.totals.invoice_count }) : null}
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
                <div className="card kpi-card">
                  <div className="kpi-label">{t('reports.kpi_net')}</div>
                  <div className="kpi-value">{formatCurrency(data.totals.net_total, 'HUF', locale)}</div>
                </div>
                <div className="card kpi-card">
                  <div className="kpi-label">{t('reports.kpi_vat')}</div>
                  <div className="kpi-value">{formatCurrency(data.totals.vat_total, 'HUF', locale)}</div>
                </div>
                <div className="card kpi-card">
                  <div className="kpi-label">{t('reports.kpi_gross')}</div>
                  <div className="kpi-value">{formatCurrency(data.totals.gross_total, 'HUF', locale)}</div>
                </div>
                <div className="card kpi-card kpi-card--alert">
                  <div className="kpi-label">{t('reports.kpi_outstanding')}</div>
                  <div className="kpi-value">{formatCurrency(data.totals.outstanding_total, 'HUF', locale)}</div>
                </div>
              </div>

              <div className="card">
                <ResponsiveContainer width="100%" height={280}>
                  <BarChart data={data.periods}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="period" fontSize={12} />
                    <YAxis fontSize={12} />
                    <Tooltip
                      formatter={(value) => formatCurrency(value, 'HUF', locale)}
                      contentStyle={{ backgroundColor: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 6 }}
                      labelStyle={{ color: 'var(--color-text)' }}
                    />
                    <Legend />
                    <Bar dataKey="net_total" name={t('reports.chart_net_revenue')} fill="var(--color-primary)" />
                    {includeReceipts && (
                      <Bar dataKey="receipt_gross_total" name={t('reports.chart_receipts')} fill="var(--color-success)" />
                    )}
                  </BarChart>
                </ResponsiveContainer>
              </div>

              <table>
                <thead>
                  <tr>
                    <th>{t('reports.col_period')}</th>
                    <th className="text-right">{t('reports.col_count')}</th>
                    <th className="text-right">{t('reports.col_net')}</th>
                    <th className="text-right">{t('reports.col_vat')}</th>
                    <th className="text-right">{t('reports.col_gross')}</th>
                    <th className="text-right">{t('reports.col_paid')}</th>
                    <th className="text-right">{t('reports.col_outstanding')}</th>
                    {includeReceipts && <th className="text-right">{t('reports.col_receipt_count')}</th>}
                    {includeReceipts && <th className="text-right">{t('reports.col_receipt_gross')}</th>}
                  </tr>
                </thead>
                <tbody>
                  {data.periods.map((row) => (
                    <tr key={row.period}>
                      <td>{row.period}</td>
                      <td className="text-right">{row.invoice_count}</td>
                      <td className="text-right">{formatCurrency(row.net_total, 'HUF', locale)}</td>
                      <td className="text-right">{formatCurrency(row.vat_total, 'HUF', locale)}</td>
                      <td className="text-right">{formatCurrency(row.gross_total, 'HUF', locale)}</td>
                      <td className="text-right">{formatCurrency(row.paid_total, 'HUF', locale)}</td>
                      <td className="text-right">{formatCurrency(row.outstanding_total, 'HUF', locale)}</td>
                      {includeReceipts && <td className="text-right">{row.receipt_count}</td>}
                      {includeReceipts && <td className="text-right">{formatCurrency(row.receipt_gross_total, 'HUF', locale)}</td>}
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
