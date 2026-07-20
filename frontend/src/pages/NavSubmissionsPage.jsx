import { Fragment, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Search, ChevronDown, ChevronRight, AlertTriangle } from 'lucide-react'
import { navSubmissions } from '../api/navSubmissions'
import { useTranslation } from '../contexts/TranslationContext'
import { NavStatusBadge } from '../components/StatusBadge'
import PerPageSelector from '../components/PerPageSelector'
import Pagination from '../components/Pagination'
import DateRangePicker from '../components/reports/DateRangePicker'
import NavSubmissionDetail from '../components/nav/NavSubmissionDetail'
import { useUrlFilters } from '../utils/useUrlFilters'
import { formatDateTime } from '../utils/format'

const STATUS_TABS = [
  { key: 'errors', labelKey: 'navlog.filter_status_errors' },
  { key: 'pending', labelKey: 'navlog.filter_status_pending' },
  { key: 'all', labelKey: 'navlog.filter_status_all' },
]

const DEFAULTS = {
  status: 'errors', invoice_number: '', date_from: '', date_to: '',
  per_page: '20', page: '1',
}

function outcomeLabel(t, status) {
  return status === 'success' ? t('navlog.outcome_success') : t('navlog.outcome_error')
}

/**
 * "Van-e bárhol NAV-beküldési probléma?" — a NAV modul monitoring-listája.
 * Egy sor = egy érintett SZÁMLA (l. NavSubmissionLogGroupedResource), nem
 * naplósor. Alapértelmezetten ?status=errors — ez a lényege: a hibás/függő
 * halmaz mérete a monitorozható mutató.
 */
export default function NavSubmissionsPage() {
  const { t, locale } = useTranslation()
  const [filters, setFilters] = useUrlFilters(DEFAULTS)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [openPopover, setOpenPopover] = useState(false)
  const [searchInput, setSearchInput] = useState(filters.invoice_number)
  const [expandedId, setExpandedId] = useState(null)
  const debounceRef = useRef(null)

  const page = Number(filters.page) || 1
  const perPage = Number(filters.per_page) || 20
  const hasDateFilter = !!(filters.date_from || filters.date_to)

  async function load() {
    setLoading(true)
    setError(false)
    try {
      const res = await navSubmissions.list({
        status: filters.status || undefined,
        invoice_number: filters.invoice_number || undefined,
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
        per_page: perPage,
        page,
      })
      setData(res.data)
    } catch {
      setError(true)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [filters.status, filters.invoice_number, filters.date_from, filters.date_to, filters.per_page, filters.page]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => {
      if (searchInput !== filters.invoice_number) {
        setFilters({ invoice_number: searchInput, page: '1' })
      }
    }, 400)
    return () => clearTimeout(debounceRef.current)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput])

  function handleStatusChange(status) {
    setExpandedId(null)
    setFilters({ status, page: '1' })
  }
  function handleDateRangeApply(dateFrom, dateTo) {
    setFilters({ date_from: dateFrom, date_to: dateTo, page: '1' })
  }
  function handlePerPage(value) {
    setFilters({ per_page: String(value), page: '1' })
  }
  function handlePageChange(p) {
    setExpandedId(null)
    setFilters({ page: String(p) })
  }
  function toggleExpanded(logId) {
    setExpandedId((prev) => (prev === logId ? null : logId))
  }

  const rows = data?.data ?? []
  const isEmptyResult = data && rows.length === 0
  const isGoodNewsEmpty = isEmptyResult && filters.status === 'errors' && !filters.invoice_number && !hasDateFilter

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('navlog.list_title')}</h1>
      </div>

      <div className="card">
        <div className="search-row" style={{ flexWrap: 'wrap', gap: 10 }}>
          <div className="fc-tabs" role="tablist">
            {STATUS_TABS.map((tab) => (
              <button
                key={tab.key}
                role="tab"
                aria-selected={filters.status === tab.key}
                className={'fc-tab' + (filters.status === tab.key ? ' active' : '')}
                onClick={() => handleStatusChange(tab.key)}
              >
                {t(tab.labelKey)}
              </button>
            ))}
          </div>

          <div className="doc-search-wrap">
            <Search size={14} className="doc-search-icon" aria-hidden="true" />
            <input
              type="text"
              className="doc-search-input"
              placeholder={t('navlog.search_ph')}
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              aria-label={t('navlog.search_ph')}
            />
          </div>

          <DateRangePicker
            unit="day"
            align="left"
            from={filters.date_from}
            to={filters.date_to}
            onApply={handleDateRangeApply}
            open={openPopover}
            onOpenChange={setOpenPopover}
            id="navlog-daterange"
          />
        </div>

        <div className="doc-table-wrap mt-4">
          {loading && !data ? (
            <p className="text-muted doc-state-message">{t('common.loading')}</p>
          ) : error ? (
            <div className="doc-state-message">
              <p className="alert-error">{t('navlog.load_error')}</p>
              <button className="btn btn-secondary" onClick={load}>{t('common.retry')}</button>
            </div>
          ) : isEmptyResult ? (
            <div className="doc-state-message">
              {isGoodNewsEmpty ? (
                <>
                  <p>{t('navlog.empty_errors_title')}</p>
                  <p className="text-muted">{t('navlog.empty_errors_hint')}</p>
                </>
              ) : (
                <p>{t('navlog.empty_generic')}</p>
              )}
            </div>
          ) : (
            <table className="doc-table">
              <thead>
                <tr>
                  <th scope="col">{t('navlog.col_invoice')}</th>
                  <th scope="col">{t('navlog.col_partner')}</th>
                  <th scope="col">{t('navlog.col_status')}</th>
                  <th scope="col">{t('navlog.col_latest')}</th>
                  <th scope="col">{t('navlog.col_attempts')}</th>
                  <th scope="col" />
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const isOpen = expandedId === row.latest.id
                  const needsAttention = row.invoice.nav_status === 'needs_attention'
                  return (
                    <Fragment key={row.invoice_id}>
                      <tr className={needsAttention ? 'navlog-row--needs-attention' : ''}>
                        <td>
                          <Link to={`/invoices/${row.invoice.id}`} className="table-link">{row.invoice.invoice_number}</Link>
                        </td>
                        <td className="doc-ellipsis" title={row.invoice.partner_name ?? ''}>{row.invoice.partner_name ?? '—'}</td>
                        <td>
                          <NavStatusBadge status={row.invoice.nav_status} />
                          {needsAttention && (
                            <span className="navlog-attention-hint" title={t('navlog.needs_attention_hint')}>
                              <AlertTriangle size={13} aria-hidden="true" />
                            </span>
                          )}
                        </td>
                        <td>
                          <div>{formatDateTime(row.latest.created_at, locale)}</div>
                          <div className="text-muted" style={{ fontSize: 12 }}>
                            {outcomeLabel(t, row.latest.status)}
                          </div>
                        </td>
                        <td>
                          {row.attempt_count} {t('navlog.attempt_count_label')}
                          {hasDateFilter && (
                            <div className="text-muted" style={{ fontSize: 11 }}>{t('navlog.attempt_count_filtered_hint')}</div>
                          )}
                        </td>
                        <td className="text-right">
                          <button type="button" className="btn btn-secondary btn-sm" onClick={() => toggleExpanded(row.latest.id)}>
                            {isOpen ? <ChevronDown size={12} /> : <ChevronRight size={12} />} {t('navlog.show_details')}
                          </button>
                        </td>
                      </tr>
                      {isOpen && (
                        <tr>
                          <td colSpan={6}>
                            <NavSubmissionDetail logId={row.latest.id} />
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
              </tbody>
            </table>
          )}
        </div>

        <div className="fc-row fc-row--status">
          <div className="doc-summary">
            {data && rows.length > 0 && (
              <span>{t('navlog.total_invoices', { count: data.meta.total })}</span>
            )}
          </div>
          <div className="doc-footer-controls">
            <PerPageSelector value={perPage} onChange={handlePerPage} />
            <Pagination meta={data?.meta} onChange={handlePageChange} />
          </div>
        </div>
      </div>
    </div>
  )
}
