import { useTranslation } from '../contexts/TranslationContext'
import { statusLabel } from '../utils/documentStatus'

export function PaymentStatusBadge({ status }) {
  const { t } = useTranslation()
  const label = { open: t('invoice.pay_open'), partial: t('invoice.pay_partial'), paid: t('invoice.pay_paid') }
  return <span className={`badge badge-pay-${status}`}>{label[status] ?? status}</span>
}

export function InvoiceStatusBadge({ status }) {
  const { t } = useTranslation()
  const label = { draft: t('invoice.st_draft'), issued: t('invoice.st_issued'), storno: t('invoice.st_storno') }
  return <span className={`badge badge-inv-${status}`}>{label[status] ?? status}</span>
}

/**
 * A bizonylatlista EGY állapot-oszlopa — a backend display_status
 * (l. DocumentController) mezőjéből, ami már eldöntötte a prioritást
 * (sztornó > piszkozat > fizetve > lejárt > részben fizetve > kiállított).
 * A "Részben fizetve"/"Fizetve"/"Piszkozat"/"Kiállított"/"Sztornózott"
 * szövegeket a meglévő invoice.* kulcsokból veszi újra — csak a "Lejárt"
 * kategória új.
 */
export function DisplayStatusBadge({ status }) {
  const { t } = useTranslation()
  return <span className={`badge badge-ds-${status}`}>{statusLabel(t, status)}</span>
}

export function AssetStatusBadge({ status }) {
  const { t } = useTranslation()
  const label = {
    active:   t('asset.status_active'),
    issued:   t('asset.status_issued'),
    service:  t('asset.status_service'),
    scrapped: t('asset.status_scrapped'),
  }
  return <span className={`badge badge-asset-${status}`}>{label[status] ?? status}</span>
}

/**
 * `invoice.nav_status` — a NAV-verdikt, NEM a beküldés-hívás kimenetele (l.
 * NavSubmissionLog.status, ami külön mező). A `needs_attention` szándékosan
 * NEM ugyanaz a szín, mint a `rejected` — az emberi beavatkozást igénylő,
 * lejárt-verdiktű eset más jellegű probléma, mint egy NAV-elutasítás.
 */
export function NavStatusBadge({ status }) {
  const { t } = useTranslation()
  const label = {
    not_applicable: t('navlog.status_not_applicable'),
    pending: t('navlog.status_pending'),
    sent: t('navlog.status_sent'),
    confirmed: t('navlog.status_confirmed'),
    confirmed_with_warnings: t('navlog.status_confirmed_with_warnings'),
    rejected: t('navlog.status_rejected'),
    needs_attention: t('navlog.status_needs_attention'),
    error: t('navlog.status_error'),
  }
  return <span className={`badge badge-nav-${status}`}>{label[status] ?? status}</span>
}

export function DocumentTypeBadge({ type }) {
  const { t } = useTranslation()
  const label = {
    invoice:         t('document.type_invoice'),
    invoice_storno:  t('document.type_inv_st'),
    receipt:         t('document.type_receipt'),
    receipt_storno:  t('document.type_rec_st'),
  }
  const cssClass = type?.replace('_', '-')
  return <span className={`badge badge-type-${cssClass}`}>{label[type] ?? type}</span>
}
