// Csoportlista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const groupColumns = [
  { key: 'name', label: 'group.group_name', default: true, locked: true },
  { key: 'description', label: 'common.description', default: true },
  { key: 'members', label: 'group.members', default: true },
  { key: 'permissions', label: 'group.permissions', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
