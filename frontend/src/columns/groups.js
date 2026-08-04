// Csoportlista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mind a 4 adatoszlop rendezhető; a kulcsok 1:1-ben megfelelnek a
// backend whitelistjének (GroupController::SORTABLE_COLUMNS) — a
// `members`/`permissions` ott a `withCount`-ból származó `users_count` /
// `permissions_count` SELECT-aliasra képződik le. Az `actions` oszlop nem adat,
// ezért nem rendezhető.
//
// `sortInitialDir: 'desc'` a darabszám-oszlopokon: "melyik csoportban van a
// legtöbb tag / jog" a várt elsődleges kérdés.
export const groupColumns = [
  { key: 'name', label: 'group.group_name', default: true, locked: true, sortable: true },
  { key: 'description', label: 'common.description', default: true, sortable: true },
  { key: 'members', label: 'group.members', default: true, sortable: true, sortInitialDir: 'desc' },
  { key: 'permissions', label: 'group.permissions', default: true, sortable: true, sortInitialDir: 'desc' },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
