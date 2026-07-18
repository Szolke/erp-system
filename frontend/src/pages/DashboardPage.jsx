import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { dashboard as dashboardApi } from '../api/dashboard'
import { useTranslation } from '../contexts/TranslationContext'
import { useToast } from '../contexts/ToastContext'
import { formatCurrency, formatDateTime } from '../utils/format'

function sumCount(totals) {
  return totals.reduce((sum, row) => sum + row.count, 0)
}

function KpiCard({ label, value, secondary, alert }) {
  return (
    <div className={`card kpi-card${alert ? ' kpi-card--alert' : ''}`}>
      <div className="kpi-label">{label}</div>
      <div className="kpi-value">{value}</div>
      {secondary && <div className="kpi-secondary">{secondary}</div>}
    </div>
  )
}

// Közös megjelenítés a devizánkénti totals-tömböt hordozó három KPI-kártyához
// (kifizetetlen / lejárt / e havi): fő szám az első deviza összege, a
// másodlagos sorban a darabszám, egy opcionális extra szöveg (pl. "legrégebbi
// N napja", "bruttó"), majd — ha van — a többi deviza összege. Egyik kártya
// sem hagyhatja ki csendben a további devizákat, mert a darabszám (sumCount)
// mindig az ÖSSZES devizán át összegez, tehát a két sor csak együtt konzisztens.
function TotalsKpiCard({ label, totals, extra, alert, t, locale }) {
  const count = sumCount(totals)
  const mainValue = totals.length > 0
    ? formatCurrency(totals[0].amount, totals[0].currency, locale)
    : formatCurrency(0, null, locale)
  const otherCurrencies = totals.slice(1).map((row) => formatCurrency(row.amount, row.currency, locale)).join(', ')

  const parts = [`${count} ${t('common.pieces')}`]
  if (extra) parts.push(extra)
  if (otherCurrencies) parts.push(otherCurrencies)

  return (
    <KpiCard
      alert={alert}
      label={label}
      value={mainValue}
      secondary={parts.join(' · ')}
    />
  )
}

function NavCard({ widget, t, locale }) {
  const { error_count: errorCount, last_sent_at: lastSentAt } = widget
  const secondary = lastSentAt
    ? t('dashboard.last_sync', { datetime: formatDateTime(lastSentAt, locale) })
    : t('dashboard.no_sync')

  return (
    <KpiCard
      label={t('dashboard.nav_label')}
      value={String(errorCount)}
      secondary={secondary}
    />
  )
}

function OldestUnpaidRow({ item, t, locale }) {
  const displayDays = -item.days_overdue
  const isOverdue = item.days_overdue > 0

  return (
    <tr>
      <td><Link to={`/invoices/${item.id}`} className="table-link">{item.number}</Link></td>
      <td>{item.partner_name ?? '—'}</td>
      <td className="text-right">{formatCurrency(item.gross_total, item.currency, locale)}</td>
      <td className={isOverdue ? 'text-danger' : 'text-muted'}>
        {t('dashboard.days_value', { days: displayDays })}
      </td>
    </tr>
  )
}

function OldestUnpaidTable({ widget, t, locale }) {
  const { items } = widget

  return (
    <div className="card">
      <h2 style={{ margin: '0 0 16px', fontSize: '1.1rem' }}>{t('dashboard.oldest_unpaid_title')}</h2>
      {items.length === 0 ? (
        <p className="text-muted">{t('dashboard.empty_no_invoices')}</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>{t('invoice.number_col')}</th>
              <th>{t('invoice.partner_col')}</th>
              <th>{t('invoice.gross_col')}</th>
              <th>{t('dashboard.col_days')}</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <OldestUnpaidRow key={item.id} item={item} t={t} locale={locale} />
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}

export default function DashboardPage() {
  const { t, locale } = useTranslation()
  const toast = useToast()
  const [widgets, setWidgets] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  async function load() {
    setLoading(true)
    setError(false)
    try {
      const res = await dashboardApi.get()
      setWidgets(res.data.widgets)
    } catch (err) {
      setError(true)
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  if (loading) {
    return <p className="text-muted">{t('common.loading')}</p>
  }

  if (error) {
    return (
      <div>
        <div className="page-header">
          <h1 className="page-title">{t('dashboard.title')}</h1>
        </div>
        <p className="alert-error">{t('dashboard.load_error')}</p>
        <button className="btn btn-secondary" onClick={load}>{t('common.retry')}</button>
      </div>
    )
  }

  if (!widgets) {
    return null
  }

  const { unpaid_invoices: unpaid, overdue_invoices: overdue, monthly_revenue: monthly, nav_status: nav, oldest_unpaid: oldestUnpaid } = widgets

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('dashboard.title')}</h1>
      </div>

      <div className="dashboard-kpis">
        {unpaid?.available && (
          <TotalsKpiCard label={t('dashboard.unpaid_label')} totals={unpaid.totals} t={t} locale={locale} />
        )}
        {overdue?.available && (
          <TotalsKpiCard
            alert
            label={t('dashboard.overdue_label')}
            totals={overdue.totals}
            extra={overdue.oldest_days_overdue !== null ? t('dashboard.oldest_overdue', { days: overdue.oldest_days_overdue }) : null}
            t={t}
            locale={locale}
          />
        )}
        {monthly?.available && (
          <TotalsKpiCard
            label={t('dashboard.monthly_label')}
            totals={monthly.totals}
            extra={t('dashboard.gross_label')}
            t={t}
            locale={locale}
          />
        )}
        {nav?.available && <NavCard widget={nav} t={t} locale={locale} />}
      </div>

      {oldestUnpaid?.available && <OldestUnpaidTable widget={oldestUnpaid} t={t} locale={locale} />}
    </div>
  )
}
