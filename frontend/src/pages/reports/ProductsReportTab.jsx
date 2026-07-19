import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts'
import { reports } from '../../api/reports'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { formatCurrency } from '../../utils/format'
import { thisYearRange } from '../../utils/reportPeriods'
import { useUrlFilters } from '../../utils/useUrlFilters'
import ReportFilterCard from '../../components/reports/ReportFilterCard'

const LIMIT = 50
const DEFAULTS = { ...thisYearRange(), date_basis: 'fulfillment', order_by: 'revenue', offset: '0' }

export default function ProductsReportTab({ activeTab, onTabChange }) {
  const { t, locale } = useTranslation()
  const toast = useToast()
  const [filters, setFilters] = useUrlFilters(DEFAULTS)
  const [data, setData] = useState(null)
  const [chartItems, setChartItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [lastUpdatedAt, setLastUpdatedAt] = useState(null)

  const offset = Number(filters.offset) || 0

  async function load() {
    setLoading(true)
    setError(false)
    try {
      const periodParams = { from: filters.from, to: filters.to, date_basis: filters.date_basis }
      const [tableRes, chartRes] = await Promise.all([
        reports.products({ ...periodParams, order_by: filters.order_by, limit: LIMIT, offset }),
        // A diagram MINDIG árbevétel szerinti top 10 — függetlenül a táblázat
        // rendezés-váltójától/lapozásától (l. feladat: "top 10 termék nettó
        // árbevétel szerint"). A backend 10 perces cache-e miatt ez a plusz
        // hívás nem drága.
        reports.products({ ...periodParams, order_by: 'revenue', limit: 10, offset: 0 }),
      ])
      setData(tableRes.data)
      setChartItems(chartRes.data.items)
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

  useEffect(() => { load() }, [filters.from, filters.to, filters.date_basis, filters.order_by, filters.offset]) // eslint-disable-line react-hooks/exhaustive-deps

  function handleCardFiltersChange(patch) {
    // Bármely elsődleges szűrő (tartomány, dátum-alap, rendezés) módosítása
    // az 1. oldalra állítja vissza a lapozást.
    setFilters({ ...patch, offset: '0' })
  }

  const totalRevenue = data?.totals.net_revenue ?? 0
  const isEmpty = data && data.items.length === 0

  return (
    <div>
      <ReportFilterCard
        variant="products"
        activeTab={activeTab}
        onTabChange={onTabChange}
        filters={{ from: filters.from, to: filters.to, date_basis: filters.date_basis, order_by: filters.order_by }}
        onFiltersChange={handleCardFiltersChange}
        exportReport="products"
        exportFilters={{ from: filters.from, to: filters.to, date_basis: filters.date_basis, order_by: filters.order_by }}
        resultLabel={data ? t('reports.count_products', { count: data.items.length }) : null}
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
              {chartItems.length > 0 && (
                <div className="card">
                  <h2 className="report-chart-title">{t('reports.chart_top_products')}</h2>
                  <ResponsiveContainer width="100%" height={Math.max(220, chartItems.length * 36)}>
                    <BarChart data={chartItems} layout="vertical" margin={{ left: 24 }}>
                      <CartesianGrid strokeDasharray="3 3" />
                      <XAxis type="number" fontSize={12} />
                      <YAxis type="category" dataKey="name" width={160} fontSize={12} />
                      <Tooltip
                        formatter={(value) => formatCurrency(value, 'HUF', locale)}
                        contentStyle={{ backgroundColor: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 6 }}
                        labelStyle={{ color: 'var(--color-text)' }}
                      />
                      <Bar dataKey="net_revenue" name={t('reports.col_net_revenue')} fill="var(--color-primary)" />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}

              <table>
                <thead>
                  <tr>
                    <th>{t('reports.col_product')}</th>
                    <th>{t('reports.col_unit')}</th>
                    <th className="text-right">{t('reports.col_quantity')}</th>
                    <th className="text-right">{t('reports.col_net_revenue')}</th>
                    <th className="text-right">{t('reports.col_invoice_count')}</th>
                    <th className="text-right">{t('reports.col_avg_price')}</th>
                    <th className="text-right">{t('reports.col_share')}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((item, i) => (
                    <tr key={item.product_id ?? `anon-${i}`}>
                      <td>
                        {item.product_id
                          ? <Link to={`/products/${item.product_id}/edit`} className="table-link">{item.name}</Link>
                          : item.name}
                      </td>
                      <td>{item.unit}</td>
                      <td className="text-right">{item.quantity}</td>
                      <td className="text-right">{formatCurrency(item.net_revenue, 'HUF', locale)}</td>
                      <td className="text-right">{item.invoice_count}</td>
                      <td className="text-right">{formatCurrency(item.average_unit_price, 'HUF', locale)}</td>
                      <td className="text-right">
                        {totalRevenue > 0 ? `${((item.net_revenue / totalRevenue) * 100).toFixed(1)}%` : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>

              <div className="pagination">
                <button className="btn btn-secondary btn-sm" disabled={offset <= 0}
                  onClick={() => setFilters({ offset: String(Math.max(0, offset - LIMIT)) })}>
                  {t('common.prev_page')}
                </button>
                <button className="btn btn-secondary btn-sm" disabled={data.items.length < LIMIT}
                  onClick={() => setFilters({ offset: String(offset + LIMIT) })}>
                  {t('common.next_page')}
                </button>
              </div>
            </>
          )}
        </>
      )}
    </div>
  )
}
