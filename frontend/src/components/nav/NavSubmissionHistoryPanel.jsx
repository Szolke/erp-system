import { useEffect, useState } from 'react'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { navSubmissions } from '../../api/navSubmissions'
import { useTranslation } from '../../contexts/TranslationContext'
import { formatDateTime } from '../../utils/format'
import NavSubmissionDetail from './NavSubmissionDetail'

const PROCESSING_RESULT_CLASS = {
  RECEIVED: 'navlog-pr--pending',
  PROCESSING: 'navlog-pr--pending',
  SAVED: 'navlog-pr--pending', // KÖZTES állapot — sose "lezárva" stílus
  DONE: 'navlog-pr--done',
  ABORTED: 'navlog-pr--aborted',
}

function operationLabel(t, operation) {
  return operation === 'queryTransactionStatus' ? t('navlog.op_query_status') : t('navlog.op_manage_invoice')
}

function processingResultLabel(t, result) {
  const keys = {
    RECEIVED: 'navlog.processing_received',
    PROCESSING: 'navlog.processing_processing',
    SAVED: 'navlog.processing_saved',
    DONE: 'navlog.processing_done',
    ABORTED: 'navlog.processing_aborted',
  }
  return result ? (t(keys[result]) ?? result) : '—'
}

/**
 * "NAV beküldési előzmények" — a számla-részletező oldal panelja. Csak
 * can('nav.log.view') esetén jelenítendő meg (a hívó — InvoiceDetailPage —
 * dönti el). Egy status=success sor NEM jelenti, hogy a NAV elfogadta a
 * számlát — csak azt, hogy a HÍVÁS lefutott; az elfogadás a számla saját
 * nav_status-ából (a lap fejlécében már megjelenő badge-ből) olvasható ki.
 */
export default function NavSubmissionHistoryPanel({ invoiceId }) {
  const { t, locale } = useTranslation()
  const [logs, setLogs] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [expandedId, setExpandedId] = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(false)
    navSubmissions.forInvoice(invoiceId)
      .then((res) => { if (!cancelled) setLogs(res.data.data) })
      .catch(() => { if (!cancelled) setError(true) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [invoiceId])

  function toggle(id) {
    setExpandedId((prev) => (prev === id ? null : id))
  }

  return (
    <div className="card">
      <strong>{t('navlog.panel_title')}</strong>

      {loading ? (
        <p className="text-muted mt-4">{t('common.loading')}</p>
      ) : error ? (
        <div className="mt-4">
          <p className="alert-error">{t('navlog.load_error')}</p>
        </div>
      ) : logs.length === 0 ? (
        <p className="text-muted mt-4">{t('navlog.empty_invoice')}</p>
      ) : (
        <ul className="navlog-timeline mt-4">
          {logs.map((log) => {
            const isOpen = expandedId === log.id
            return (
              <li key={log.id} className="navlog-timeline-item">
                <button
                  type="button"
                  className="navlog-timeline-row"
                  onClick={() => toggle(log.id)}
                  aria-expanded={isOpen}
                >
                  {isOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                  <span className="navlog-timeline-time">{formatDateTime(log.created_at, locale)}</span>
                  <span className="navlog-timeline-op">{operationLabel(t, log.operation)}</span>
                  <span className={`navlog-outcome navlog-outcome--${log.status}`}>
                    {log.status === 'success' ? t('navlog.outcome_success') : t('navlog.outcome_error')}
                  </span>
                  <span className={`navlog-pr ${PROCESSING_RESULT_CLASS[log.processing_result] ?? ''}`}>
                    {processingResultLabel(t, log.processing_result)}
                  </span>
                  <span className="navlog-timeline-trx text-muted">{log.transaction_id ?? '—'}</span>
                </button>
                {isOpen && (
                  <div className="navlog-timeline-detail">
                    <NavSubmissionDetail logId={log.id} />
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
