import { useTranslation } from '../contexts/TranslationContext'

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
