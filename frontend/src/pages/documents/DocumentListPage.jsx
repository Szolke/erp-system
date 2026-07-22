import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Search, AlertTriangle } from 'lucide-react'
import { documents } from '../../api/documents'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { DisplayStatusBadge, DocumentTypeBadge, PaymentStatusBadge } from '../../components/StatusBadge'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'
import DateRangePicker from '../../components/reports/DateRangePicker'
import DocumentFiltersPopover from '../../components/documents/DocumentFiltersPopover'
import NewDocumentButton from '../../components/documents/NewDocumentButton'
import ExportButton from '../../components/ExportButton'
import ColumnPicker from '../../components/ColumnPicker'
import { useListColumns } from '../../hooks/useListColumns'
import { documentColumns } from '../../columns/documents'
import { useUrlFilters } from '../../utils/useUrlFilters'
import { formatCurrency } from '../../utils/format'
import { statusLabel } from '../../utils/documentStatus'

const TABS = [
  { key: '', labelKey: 'document.type_all' },
  { key: 'invoice', labelKey: 'document.type_invoice', needsPerm: 'invoice.view' },
  { key: 'receipt', labelKey: 'document.type_receipt', needsPerm: 'receipt.view' },
  { key: 'storno', labelKey: 'document.type_storno' },
]

const TYPE_LABEL_KEYS = {
  invoice: 'document.type_invoice',
  invoice_storno: 'document.type_inv_st',
  receipt: 'document.type_receipt',
  receipt_storno: 'document.type_rec_st',
}

const DEFAULTS = {
  type: '', status: '', currency: '', search: '',
  date_from: '', date_to: '', per_page: '20', page: '1',
}

function isWithinOneYear(from, to) {
  if (!from || !to) return false
  return (new Date(to) - new Date(from)) <= 366 * 24 * 60 * 60 * 1000
}

function formatIssueDate(dateStr, locale, short) {
  const opts = short ? { month: 'short', day: 'numeric' } : { year: 'numeric', month: 'short', day: 'numeric' }
  return new Intl.DateTimeFormat(locale, opts).format(new Date(dateStr))
}

function docLink(doc) {
  return doc.model_type === 'invoice' ? `/invoices/${doc.id}` : `/receipts/${doc.id}`
}

// Oszloponkénti <td> renderelés a documentColumns regisztry kulcsai szerint —
// a látható oszlopok halmaza a useListColumns hooktól függ, ezért a fejléc
// százalékos szélességei (a korábbi, fix 5-oszlopos verzióban) itt szükségképp
// elesnek: változó oszlopszám mellett a böngésző automatikus oszlopszélessége
// az egyetlen konzisztens megoldás (ugyanígy a számlalistán is).
function renderDocumentCell(key, doc, { t, locale, shortDate }) {
  switch (key) {
    case 'number':
      return (
        <td key="number">
          <Link to={docLink(doc)} className="table-link">{doc.document_number}</Link>
          <div className="doc-subline">
            {t(TYPE_LABEL_KEYS[doc.document_type] ?? doc.document_type)}
            {doc.currency !== 'HUF' && <span className="doc-currency-marker"> · {doc.currency}</span>}
          </div>
        </td>
      )
    case 'type':
      return <td key="type"><DocumentTypeBadge type={doc.document_type} /></td>
    case 'partner':
      return <td key="partner" className="doc-ellipsis" title={doc.partner_name ?? ''}>{doc.partner_name ?? '—'}</td>
    case 'partner_tax_number':
      return <td key="partner_tax_number">{doc.partner_tax_number ?? '—'}</td>
    case 'issue_date':
      return <td key="issue_date">{formatIssueDate(doc.issue_date, locale, shortDate)}</td>
    // A nyugtának nincs teljesítés-dátuma külön mezőként tárolva minden esetben
    // sem, ezért itt is (mint a due_date-nél) null-biztosan kezeljük.
    case 'fulfillment_date':
      return <td key="fulfillment_date">{doc.fulfillment_date ? formatIssueDate(doc.fulfillment_date, locale, shortDate) : '—'}</td>
    // A nyugtának NINCS fizetési határideje (backend: NULL::date AS due_date) — szándékos üres cella.
    case 'due_date':
      return <td key="due_date">{doc.due_date ? formatIssueDate(doc.due_date, locale, shortDate) : '—'}</td>
    case 'net_total':
      return <td key="net_total" className="text-right">{formatCurrency(doc.net_total, null, locale)}</td>
    case 'vat_total':
      return <td key="vat_total" className="text-right">{formatCurrency(doc.vat_total, null, locale)}</td>
    case 'gross': {
      const isNegative = Number(doc.gross_total) < 0
      return (
        <td key="gross" className={'text-right doc-amount' + (isNegative ? ' text-danger' : '')}>
          {formatCurrency(doc.gross_total, null, locale)}
        </td>
      )
    }
    // gross_total_huf a HufConversion "skip" ágon NULL lehet (érvénytelen/hiányzó
    // árfolyam) — l. DocumentController::index() summary.skipped_count.
    case 'gross_huf':
      return <td key="gross_huf" className="text-right">{doc.gross_total_huf != null ? formatCurrency(doc.gross_total_huf, 'HUF', locale) : '—'}</td>
    case 'currency':
      return <td key="currency">{doc.currency}</td>
    // A nyugtának NINCS fizetési státusza (backend: NULL AS payment_status) — szándékos üres cella.
    case 'payment_status':
      return <td key="payment_status">{doc.payment_status ? <PaymentStatusBadge status={doc.payment_status} /> : '—'}</td>
    case 'status':
      return <td key="status"><DisplayStatusBadge status={doc.display_status} /></td>
    default:
      return null
  }
}

export default function DocumentListPage() {
  const { can } = useAuth()
  const { t, locale } = useTranslation()
  const [filters, setFilters] = useUrlFilters(DEFAULTS)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [openPopover, setOpenPopover] = useState(null)
  const [searchInput, setSearchInput] = useState(filters.search)
  const debounceRef = useRef(null)
  const { allColumns, visibleColumns, isVisible, toggle, reorder, reset, isDirty } = useListColumns('documents.index', documentColumns)

  const page = Number(filters.page) || 1
  const perPage = Number(filters.per_page) || 20

  async function load() {
    setLoading(true)
    setError(false)
    try {
      const res = await documents.list({
        type: filters.type || undefined,
        status: filters.status || undefined,
        currency: filters.currency || undefined,
        search: filters.search || undefined,
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

  useEffect(() => { load() }, [filters.type, filters.status, filters.currency, filters.search, filters.date_from, filters.date_to, filters.per_page, filters.page]) // eslint-disable-line react-hooks/exhaustive-deps

  // Debounce (~400ms): a React state minden leütést megőriz, csak a
  // lekérdezés-indítás késleltetett — karakter emiatt sosem vész el.
  useEffect(() => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => {
      if (searchInput !== filters.search) {
        setFilters({ search: searchInput, page: '1' })
      }
    }, 400)
    return () => clearTimeout(debounceRef.current)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput])

  function handleTabChange(type) {
    setFilters({ type, page: '1' })
  }
  function handleDateRangeApply(dateFrom, dateTo) {
    setFilters({ date_from: dateFrom, date_to: dateTo, page: '1' })
  }
  function handleFiltersChange(patch) {
    setFilters({ ...patch, page: '1' })
  }
  function handlePerPage(value) {
    setFilters({ per_page: String(value), page: '1' })
  }
  function handlePageChange(p) {
    setFilters({ page: String(p) })
  }
  function clearAllFilters() {
    setSearchInput('')
    setFilters({ type: '', status: '', currency: '', search: '', date_from: '', date_to: '', page: '1' })
  }
  function clearChipFilters() {
    setFilters({ status: '', currency: '', page: '1' })
  }

  const visibleTabs = TABS.filter((tabDef) => !tabDef.needsPerm || can(tabDef.needsPerm))
  const shortDate = isWithinOneYear(filters.date_from, filters.date_to)

  const chips = []
  if (filters.status) {
    chips.push({ key: 'status', label: statusLabel(t, filters.status), onRemove: () => handleFiltersChange({ status: '' }) })
  }
  if (filters.currency) {
    chips.push({ key: 'currency', label: filters.currency, onRemove: () => handleFiltersChange({ currency: '' }) })
  }

  const hasAnyFilter = !!(filters.type || filters.status || filters.currency || filters.search || filters.date_from || filters.date_to)
  const isEmptyResult = data && data.data.length === 0
  const isTrulyEmpty = isEmptyResult && !hasAnyFilter

  const exportParams = {
    type: filters.type || undefined,
    status: filters.status || undefined,
    currency: filters.currency || undefined,
    search: filters.search || undefined,
    date_from: filters.date_from || undefined,
    date_to: filters.date_to || undefined,
  }
  const exportFilename = (filters.date_from && filters.date_to)
    ? `bizonylatok-${filters.date_from}-${filters.date_to}.csv`
    : 'bizonylatok-export.csv'

  return (
    <div className="fc-card">
      <div className="fc-row fc-row--header">
        <div className="fc-title-tabs">
          <h1 className="fc-title">{t('document.title')}</h1>
          <div className="fc-tabs" role="tablist">
            {visibleTabs.map((tabDef) => (
              <button
                key={tabDef.key || 'all'}
                role="tab"
                aria-selected={filters.type === tabDef.key}
                className={'fc-tab' + (filters.type === tabDef.key ? ' active' : '')}
                onClick={() => handleTabChange(tabDef.key)}
              >
                {t(tabDef.labelKey)}
              </button>
            ))}
          </div>
        </div>
        <div className="fc-header-actions">
          <ExportButton
            visible={can('invoice.view') || can('receipt.view')}
            onExport={() => documents.export(exportParams)}
            filename={exportFilename}
            label={t('document.export_button')}
            exportingLabel={t('document.exporting')}
            errorLabel={t('document.export_error')}
          />
          <NewDocumentButton />
        </div>
      </div>

      <div className="fc-row fc-row--filters">
        <div className="fc-row-left doc-filters-left">
          <div className="doc-search-wrap">
            <Search size={14} className="doc-search-icon" aria-hidden="true" />
            <input
              type="text"
              className="doc-search-input"
              placeholder={t('document.search_ph')}
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              aria-label={t('document.search_ph')}
            />
            {loading && <span className="doc-search-spinner" aria-hidden="true" />}
          </div>

          <DateRangePicker
            unit="day"
            align="right"
            from={filters.date_from}
            to={filters.date_to}
            onApply={handleDateRangeApply}
            open={openPopover === 'daterange'}
            onOpenChange={(o) => setOpenPopover(o ? 'daterange' : null)}
            id="document-daterange"
          />

          <DocumentFiltersPopover
            filters={filters}
            onChange={handleFiltersChange}
            open={openPopover === 'filters'}
            onOpenChange={(o) => setOpenPopover(o ? 'filters' : null)}
            id="document-filters"
          />

          <ColumnPicker
            columns={allColumns}
            isVisible={isVisible}
            onToggle={toggle}
            onReorder={reorder}
            onReset={reset}
            isDirty={isDirty}
            open={openPopover === 'columns'}
            onOpenChange={(o) => setOpenPopover(o ? 'columns' : null)}
            id="document-columns"
            align="right"
          />
        </div>
      </div>

      {chips.length > 0 && (
        <div className="fc-row fc-row--chips">
          <div className="fc-chip-row">
            {chips.map((chip) => (
              <span key={chip.key} className="fc-chip">
                {chip.label}
                <button type="button" onClick={chip.onRemove} aria-label={t('reports.remove_filter', { name: chip.label })}>×</button>
              </span>
            ))}
            <button type="button" className="fc-clear-all" onClick={clearChipFilters}>{t('reports.clear_all')}</button>
          </div>
        </div>
      )}

      <div className="doc-table-wrap">
        {loading && !data ? (
          <p className="text-muted doc-state-message">{t('common.loading')}</p>
        ) : error ? (
          <div className="doc-state-message">
            <p className="alert-error">{t('document.load_error')}</p>
            <button className="btn btn-secondary" onClick={load}>{t('common.retry')}</button>
          </div>
        ) : isEmptyResult ? (
          <div className="doc-state-message">
            {isTrulyEmpty ? (
              <>
                <p>{t('document.empty_title')}</p>
                <p className="text-muted">{t('document.empty_hint')}</p>
              </>
            ) : (
              <>
                <p>{t('document.no_results_title')}</p>
                <button className="btn btn-secondary" onClick={clearAllFilters}>{t('document.clear_filters')}</button>
              </>
            )}
          </div>
        ) : (
          <table className="doc-table">
            <thead>
              <tr>
                {visibleColumns.map((col) => (
                  <th key={col.key} scope="col" className={col.align === 'right' ? 'text-right' : undefined}>{t(col.label)}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.data.map((doc) => (
                <tr key={`${doc.model_type}-${doc.id}`}>
                  {visibleColumns.map((col) => renderDocumentCell(col.key, doc, { t, locale, shortDate }))}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="fc-row fc-row--status">
        <div className="doc-summary">
          {data && data.data.length > 0 && (
            <span>
              {t('document.summary_line', { count: data.summary.count, amount: formatCurrency(data.summary.gross_total_huf, 'HUF', locale) })}
              {data.summary.skipped_count > 0 && (
                <span className="doc-summary-warning" title={t('document.summary_warning', { count: data.summary.skipped_count })}>
                  <AlertTriangle size={13} aria-hidden="true" />
                </span>
              )}
            </span>
          )}
        </div>
        <div className="doc-footer-controls">
          <PerPageSelector value={perPage} onChange={handlePerPage} />
          <Pagination meta={data?.meta} onChange={handlePageChange} />
        </div>
      </div>
    </div>
  )
}
