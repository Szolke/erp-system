// NAV napló lista oszlop-regisztere — a useListColumns hook ebből olvassa
// ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: négy oszlop; a kulcsok 1:1-ben megfelelnek a backend
// whitelistjének (NavSubmissionLogController::SORTABLE_COLUMNS) — az `invoice`
// az `invoices.invoice_number`, a `partner` a `partners.name`, a `status` az
// `invoices.nav_status`, a `latest` a `nav_submission_logs.created_at`
// oszlopra képződik le (a meglévő `joinSub`-hoz kapcsolt joinokon át).
//
// Az `attempts` oszlop SZÁNDÉKOSAN nem rendezhető: az érték PHP-oldalon,
// utólag számolódik ki (nem a lekérdezésben), ezért a backend whitelistjében
// sem szerepel — nincs mire ORDER BY-t tenni. Az `actions` oszlop nem adat.
export const navSubmissionColumns = [
  { key: 'invoice', label: 'navlog.col_invoice', default: true, locked: true, sortable: true },
  { key: 'partner', label: 'navlog.col_partner', default: true, sortable: true },
  { key: 'status', label: 'navlog.col_status', default: true, sortable: true },
  { key: 'latest', label: 'navlog.col_latest', default: true, sortable: true, sortInitialDir: 'desc' },
  { key: 'attempts', label: 'navlog.col_attempts', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
