import { useEffect, useState } from 'react'
import { Copy, Check } from 'lucide-react'
import { navSubmissions } from '../../api/navSubmissions'
import { useTranslation } from '../../contexts/TranslationContext'
import { formatDateTime } from '../../utils/format'
import NavValidationMessages from './NavValidationMessages'

function CopyButton({ text }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(text ?? '')
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch {
      // Clipboard API elérhetetlen (pl. nem-secure kontextus) — csendben eldobjuk,
      // a felhasználó kézzel is kijelölheti a szöveget.
    }
  }

  if (!text) return null

  return (
    <button type="button" className="btn btn-secondary btn-sm navlog-copy-btn" onClick={handleCopy}>
      {copied ? <><Check size={12} /> {t('navlog.copied')}</> : <><Copy size={12} /> {t('navlog.copy')}</>}
    </button>
  )
}

/**
 * Egy naplóbejegyzés részletei, KÉRÉSRE betöltve (a nyers request_xml/
 * response_xml SOSE a lista-válaszban utazik — l. NavSubmissionLogController).
 * Csak akkor mountolódik, amikor a szülő ténylegesen lenyitja a sort.
 */
export default function NavSubmissionDetail({ logId }) {
  const { t, locale } = useTranslation()
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(false)
    navSubmissions.get(logId)
      .then((res) => { if (!cancelled) setDetail(res.data.data) })
      .catch(() => { if (!cancelled) setError(true) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [logId])

  if (loading) return <p className="text-muted navlog-detail-state">{t('common.loading')}</p>
  if (error || !detail) return <p className="alert-error navlog-detail-state">{t('navlog.detail_load_error')}</p>

  return (
    <div className="navlog-detail">
      <div className="navlog-detail-meta">
        <div className="info-row">
          <span className="info-label">{t('navlog.col_time')}</span>
          <span className="info-value">{formatDateTime(detail.created_at, locale)}</span>
        </div>
        <div className="info-row">
          <span className="info-label">{t('navlog.transaction_id')}</span>
          <span className="info-value">{detail.transaction_id ?? '—'}</span>
        </div>
        <div className="info-row">
          <span className="info-label">{t('navlog.environment')}</span>
          <span className="info-value">{detail.environment ?? '—'}</span>
        </div>
        {detail.error_message && (
          <div className="info-row">
            <span className="info-label">{t('navlog.error_message')}</span>
            <span className="info-value">{detail.error_message}</span>
          </div>
        )}
      </div>

      <NavValidationMessages messages={detail.validation_messages} />

      {detail.request_xml && (
        <details className="navlog-xml-block">
          <summary>{t('navlog.raw_xml_request')}</summary>
          <div className="navlog-xml-toolbar"><CopyButton text={detail.request_xml} /></div>
          <pre className="navlog-xml-pre">{detail.request_xml}</pre>
        </details>
      )}
      {detail.response_xml && (
        <details className="navlog-xml-block">
          <summary>{t('navlog.raw_xml_response')}</summary>
          <div className="navlog-xml-toolbar"><CopyButton text={detail.response_xml} /></div>
          <pre className="navlog-xml-pre">{detail.response_xml}</pre>
        </details>
      )}
      {!detail.request_xml && !detail.response_xml && (
        <p className="text-muted navlog-detail-state">{t('navlog.no_raw_xml')}</p>
      )}
    </div>
  )
}
