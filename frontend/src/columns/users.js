// Felhasználólista oszlop-regisztere — a useListColumns hook ebből olvassa
// ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: három oszlop; a kulcsok 1:1-ben megfelelnek a backend
// whitelistjének (UserController::SORTABLE_COLUMNS) — a `status` ott az
// `is_active` oszlopra képződik le. A `groups` oszlop SZÁNDÉKOSAN nem
// rendezhető: több-a-többhöz kapcsolat, egy soron több badge is állhat, tehát
// nincs egyetlen olyan DB-oszlop, ami szerint értelmesen rendezhető lenne. Az
// `actions` oszlop nem adat, ezért szintén nem rendezhető.
export const userColumns = [
  { key: 'name', label: 'user.name', default: true, locked: true, sortable: true },
  { key: 'email', label: 'user.email', default: true, sortable: true },
  { key: 'groups', label: 'user.groups_col', default: true },
  { key: 'status', label: 'user.status_col', default: true, sortable: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
