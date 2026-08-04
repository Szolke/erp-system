// Munkakör-lista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mind a 4 adatoszlop rendezhető; a kulcsok 1:1-ben megfelelnek a
// backend whitelistjének (JobPositionController::SORTABLE_COLUMNS) — a `scope`
// ott a `(company_id IS NULL)`, a `status` az `active` oszlopra képződik le. Az
// `actions` oszlop nem adat, ezért nem rendezhető.
//
// A `sort_order` szándékosan `asc`-cel indul: ez POZÍCIÓ, nem mennyiség — a
// "0, 1, 2 …" a természetes olvasata (egyben a lista alapértelmezett rendezése).
export const jobPositionColumns = [
  { key: 'name', label: 'common.name', default: true, locked: true, sortable: true },
  { key: 'scope', label: 'job_position.scope_col', default: true, sortable: true },
  { key: 'status', label: 'job_position.status_col', default: true, sortable: true },
  { key: 'sort_order', label: 'job_position.sort_order_col', default: true, sortable: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
