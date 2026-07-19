/**
 * A backend display_status (l. DocumentController) emberi-olvasható címkéje —
 * a DocumentFiltersPopover (szűrő-select) és a DocumentListPage (chip-címke)
 * egyaránt ugyanazt a leképezést használja.
 */
export function statusLabel(t, status) {
  return {
    paid: t('invoice.pay_paid'),
    overdue: t('document.status_overdue'),
    partially_paid: t('invoice.pay_partial'),
    issued: t('invoice.st_issued'),
    draft: t('invoice.st_draft'),
    storno: t('invoice.st_storno'),
  }[status] ?? status
}
