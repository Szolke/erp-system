// NAV napló lista oszlop-regisztere — a useListColumns hook ebből olvassa
// ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const navSubmissionColumns = [
  { key: 'invoice', label: 'navlog.col_invoice', default: true, locked: true },
  { key: 'partner', label: 'navlog.col_partner', default: true },
  { key: 'status', label: 'navlog.col_status', default: true },
  { key: 'latest', label: 'navlog.col_latest', default: true },
  { key: 'attempts', label: 'navlog.col_attempts', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
