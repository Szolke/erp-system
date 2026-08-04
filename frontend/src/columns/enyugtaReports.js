// NAV eNyugta jelentések lista oszlop-regisztere — a useListColumns hook
// ebből olvassa ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mind az 5 oszlop rendezhető; a kulcsok 1:1-ben megfelelnek a
// backend whitelistjének (EnyugtaReportController::SORTABLE_COLUMNS) — a
// `gross_total` ott a `total_gross` DB-oszlopra képződik le.
//
// `sortInitialDir: 'desc'`: dátumnál, összegnél és darabszámnál a
// "legfrissebb/legnagyobb elöl" a várt elsődleges nézet, szövegnél viszont az
// ábécésorrend.
export const enyugtaReportColumns = [
  { key: 'report_date', label: 'enyugta.report_date_col', default: true, locked: true, sortable: true, sortInitialDir: 'desc' },
  { key: 'type', label: 'enyugta.type_col', default: true, sortable: true },
  { key: 'status', label: 'enyugta.status_col', default: true, sortable: true },
  { key: 'receipt_count', label: 'enyugta.receipt_count_col', default: true, sortable: true, sortInitialDir: 'desc' },
  { key: 'gross_total', label: 'enyugta.gross_total_col', default: true, align: 'right', sortable: true, sortInitialDir: 'desc' },
]
