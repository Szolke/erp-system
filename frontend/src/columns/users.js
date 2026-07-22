// Felhasználólista oszlop-regisztere — a useListColumns hook ebből olvassa
// ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const userColumns = [
  { key: 'name', label: 'user.name', default: true, locked: true },
  { key: 'email', label: 'user.email', default: true },
  { key: 'groups', label: 'user.groups_col', default: true },
  { key: 'status', label: 'user.status_col', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
