// NAV eNyugta jelentések lista oszlop-regisztere — a useListColumns hook
// ebből olvassa ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const enyugtaReportColumns = [
  { key: 'report_date', label: 'enyugta.report_date_col', default: true, locked: true },
  { key: 'type', label: 'enyugta.type_col', default: true },
  { key: 'status', label: 'enyugta.status_col', default: true },
  { key: 'receipt_count', label: 'enyugta.receipt_count_col', default: true },
  { key: 'gross_total', label: 'enyugta.gross_total_col', default: true, align: 'right' },
]
