// Értékesítő csoportok lista oszlop-regisztere — a useListColumns hook
// ebből olvassa ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mindkét adatoszlop rendezhető; a kulcsok 1:1-ben megfelelnek a
// backend whitelistjének (SalesGroupController::SORTABLE_COLUMNS) — a
// `display_name` ott egy CASE-alias (cég-prefix + név), tehát a képernyőn látott
// összefűzött név szerint rendez, nem a nyers `name` szerint. Az `actions`
// oszlop nem adat, ezért nem rendezhető.
export const salesGroupColumns = [
  { key: 'display_name', label: 'sales_group.display_name_col', default: true, locked: true, sortable: true },
  { key: 'name', label: 'sales_group.internal_name_col', default: true, sortable: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
