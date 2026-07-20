import { useTranslation } from '../../contexts/TranslationContext'

/**
 * Egy queryTransactionStatus-kísérlet technical/business validation üzenetei,
 * súlyosság szerint megkülönböztetve. Az ERROR és a WARN NEM ugyanaz — a WARN
 * egy ELFOGADOTT számlát jelöl (l. NavTransactionStatusChecker), ezért soha
 * nem kaphat piros stílust, csak sárga/figyelmeztető.
 */
export default function NavValidationMessages({ messages }) {
  const { t } = useTranslation()

  if (!messages || messages.length === 0) return null

  const severityLabel = {
    ERROR: t('navlog.severity_error'),
    WARN: t('navlog.severity_warn'),
    INFO: t('navlog.severity_info'),
  }

  return (
    <ul className="navlog-messages">
      {messages.map((m, i) => (
        <li key={i} className={`navlog-message navlog-message--${(m.severity ?? '').toLowerCase()}`}>
          <span className="navlog-message-severity">{severityLabel[m.severity] ?? m.severity}</span>
          {m.code && <span className="navlog-message-code">{m.code}</span>}
          <span className="navlog-message-text">{m.message}</span>
        </li>
      ))}
    </ul>
  )
}
