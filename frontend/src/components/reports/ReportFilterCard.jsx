import { useRef, useState } from 'react'
import { partners as partnersApi } from '../../api/partners'
import { reports } from '../../api/reports'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useNow } from '../../utils/useNow'
import { thisMonthRange, prevMonthRange, thisYearRange, prevYearRange } from '../../utils/reportPeriods'
import ExportButton from '../ExportButton'
import DateRangePicker from './DateRangePicker'
import DateBasisDropdown from './DateBasisDropdown'
import FiltersPopover from './FiltersPopover'

const TAB_KEYS = ['invoices', 'products', 'aging', 'vat']

function formatUpdatedAt(t, updatedAt, now) {
  const minutes = Math.floor((now - updatedAt.getTime()) / 60000)
  if (minutes < 1) return t('reports.updated_now')
  if (minutes < 60) return t('reports.updated_minutes_ago', { minutes })
  return t('reports.updated_hours_ago', { hours: Math.floor(minutes / 60) })
}

function statusLabel(t, status) {
  return { open: t('invoice.pay_open'), partial: t('invoice.pay_partial'), paid: t('invoice.pay_paid') }[status] ?? status
}

function exportFilename(report, filters) {
  const from = filters.from ?? filters.as_of ?? null
  const to = filters.to ?? filters.as_of ?? null
  return (from !== null && to !== null) ? `reports-${report}-${from}-${to}.csv` : `reports-${report}-export.csv`
}

/**
 * A Kimutatások oldal EGYETLEN szűrő-kártyája — a `variant` (tab-típus)
 * dönti el, mely sorok/mezők jelennek meg, nincs négyszeres másolat.
 * Négy sor: (1) cím + fülek + export, (2) elsődleges szűrők, (3) gyorsválasztók
 * (kintlévőségnél nincs), (4) aktív szűrő-chipek + eredmény-visszajelzés.
 *
 * A három popover (dátum-tartomány, dátum-alapja, szűrők) közül egyszerre
 * csak egy lehet nyitva — ezt itt, a szülőben tartott `openPopover` egyetlen
 * state-je garantálja, nem külön belső open-state popoverenként.
 */
export default function ReportFilterCard({
  variant,
  activeTab,
  onTabChange,
  filters,
  onFiltersChange,
  exportReport,
  exportFilters,
  resultLabel,
  lastUpdatedAt,
}) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const now = useNow()
  const [openPopover, setOpenPopover] = useState(null)
  const [partnerOptions, setPartnerOptions] = useState(null)
  const tabRefs = useRef([])

  function loadPartnersOnce() {
    if (partnerOptions !== null) return
    partnersApi.list({ per_page: 200 })
      .then((res) => setPartnerOptions(res.data.data ?? []))
      .catch(() => setPartnerOptions([]))
  }

  function partnerName(id) {
    return partnerOptions?.find((p) => String(p.id) === String(id))?.name ?? `#${id}`
  }

  function handleTabKeyDown(e, index) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
    e.preventDefault()
    const dir = e.key === 'ArrowRight' ? 1 : -1
    const nextIndex = (index + dir + TAB_KEYS.length) % TAB_KEYS.length
    onTabChange(TAB_KEYS[nextIndex])
    tabRefs.current[nextIndex]?.focus()
  }

  const showDateRange = variant !== 'aging'
  const showFiltersPopover = variant === 'invoices' || variant === 'aging'
  const showQuickRow = variant !== 'aging'

  const quickRanges = [
    { key: 'this_month', label: t('reports.filter_this_month'), range: thisMonthRange() },
    { key: 'prev_month', label: t('reports.filter_prev_month'), range: prevMonthRange() },
    { key: 'this_year', label: t('reports.filter_this_year'), range: thisYearRange() },
    { key: 'prev_year', label: t('reports.filter_prev_year'), range: prevYearRange() },
  ]

  const chips = []
  if (variant === 'invoices' && filters.include_receipts) {
    chips.push({ key: 'include_receipts', label: t('reports.filter_include_receipts'), onRemove: () => onFiltersChange({ include_receipts: false }) })
  }
  if (variant === 'invoices' && filters.status) {
    chips.push({ key: 'status', label: statusLabel(t, filters.status), onRemove: () => onFiltersChange({ status: null }) })
  }
  if (showFiltersPopover && filters.partner_id) {
    chips.push({ key: 'partner', label: partnerName(filters.partner_id), onRemove: () => onFiltersChange({ partner_id: null }) })
  }

  function clearAllChips() {
    const patch = {}
    if (variant === 'invoices') { patch.include_receipts = false; patch.status = null }
    if (showFiltersPopover) patch.partner_id = null
    onFiltersChange(patch)
  }

  return (
    <div className="fc-card">
      <div className="fc-row fc-row--header">
        <div className="fc-title-tabs">
          <h1 className="fc-title">{t('reports.title')}</h1>
          <div className="fc-tabs" role="tablist">
            {TAB_KEYS.map((key, index) => (
              <button
                key={key}
                ref={(el) => { tabRefs.current[index] = el }}
                role="tab"
                aria-selected={activeTab === key}
                tabIndex={activeTab === key ? 0 : -1}
                className={'fc-tab' + (activeTab === key ? ' active' : '')}
                onClick={() => onTabChange(key)}
                onKeyDown={(e) => handleTabKeyDown(e, index)}
              >
                {t(`reports.tab_${key}`)}
              </button>
            ))}
          </div>
        </div>
        <ExportButton
          visible={can('report.export')}
          onExport={() => reports.export(exportReport, exportFilters)}
          filename={exportFilename(exportReport, exportFilters)}
          label={t('reports.export_button')}
          exportingLabel={t('reports.exporting')}
          errorLabel={t('reports.export_error')}
        />
      </div>

      <div className="fc-row fc-row--filters">
        <div className="fc-row-left">
          {showDateRange ? (
            <>
              <DateRangePicker
                from={filters.from}
                to={filters.to}
                onApply={(from, to) => onFiltersChange({ from, to })}
                open={openPopover === 'daterange'}
                onOpenChange={(o) => setOpenPopover(o ? 'daterange' : null)}
                id={`fc-daterange-${variant}`}
              />
              <DateBasisDropdown
                value={filters.date_basis}
                onChange={(v) => onFiltersChange({ date_basis: v })}
                open={openPopover === 'basis'}
                onOpenChange={(o) => setOpenPopover(o ? 'basis' : null)}
                id={`report-datebasis-${variant}`}
              />
            </>
          ) : (
            <label className="fc-field">
              <span className="fc-field-label">{t('reports.filter_as_of')}</span>
              <input type="date" value={filters.as_of} onChange={(e) => onFiltersChange({ as_of: e.target.value })} />
            </label>
          )}

          {showFiltersPopover && (
            <FiltersPopover
              variant={variant}
              filters={filters}
              onChange={onFiltersChange}
              open={openPopover === 'filters'}
              onOpenChange={(o) => setOpenPopover(o ? 'filters' : null)}
              id={`report-filters-${variant}`}
              partnerOptions={partnerOptions}
              onNeedPartners={loadPartnersOnce}
            />
          )}
        </div>

        <div className="fc-row-right">
          {variant === 'invoices' && (
            <div className="fc-segmented" role="radiogroup" aria-label={t('reports.filter_granularity')}>
              <button type="button" role="radio" aria-checked={filters.granularity === 'month'}
                className={filters.granularity === 'month' ? 'active' : ''}
                onClick={() => onFiltersChange({ granularity: 'month' })}>{t('reports.granularity_month')}</button>
              <button type="button" role="radio" aria-checked={filters.granularity === 'day'}
                className={filters.granularity === 'day' ? 'active' : ''}
                onClick={() => onFiltersChange({ granularity: 'day' })}>{t('reports.granularity_day')}</button>
            </div>
          )}
          {variant === 'products' && (
            <div className="fc-segmented" role="radiogroup" aria-label={t('reports.order_by_label')}>
              <button type="button" role="radio" aria-checked={filters.order_by === 'revenue'}
                className={filters.order_by === 'revenue' ? 'active' : ''}
                onClick={() => onFiltersChange({ order_by: 'revenue' })}>{t('reports.order_by_revenue')}</button>
              <button type="button" role="radio" aria-checked={filters.order_by === 'quantity'}
                className={filters.order_by === 'quantity' ? 'active' : ''}
                onClick={() => onFiltersChange({ order_by: 'quantity' })}>{t('reports.order_by_quantity')}</button>
            </div>
          )}
        </div>
      </div>

      {showQuickRow && (
        <div className="fc-row fc-row--quick">
          <span className="report-quick-label">{t('reports.quick_label')}</span>
          <div className="report-quick-pills">
            {quickRanges.map((q) => (
              <button
                key={q.key}
                type="button"
                className={'report-quick-pill' + (filters.from === q.range.from && filters.to === q.range.to ? ' active' : '')}
                onClick={() => onFiltersChange(q.range)}
              >
                {q.label}
              </button>
            ))}
          </div>
        </div>
      )}

      <div className="fc-row fc-row--status">
        <div className="fc-chip-row">
          {chips.map((chip) => (
            <span key={chip.key} className="fc-chip">
              {chip.label}
              <button type="button" onClick={chip.onRemove} aria-label={t('reports.remove_filter', { name: chip.label })}>×</button>
            </span>
          ))}
          {chips.length > 0 && (
            <button type="button" className="fc-clear-all" onClick={clearAllChips}>{t('reports.clear_all')}</button>
          )}
        </div>
        <div className="fc-result-status">
          {resultLabel && <span>{resultLabel}</span>}
          {resultLabel && lastUpdatedAt && <span> · {formatUpdatedAt(t, lastUpdatedAt, now)}</span>}
        </div>
      </div>
    </div>
  )
}
