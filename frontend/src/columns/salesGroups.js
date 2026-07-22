// Értékesítő csoportok lista oszlop-regisztere — a useListColumns hook
// ebből olvassa ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const salesGroupColumns = [
  { key: 'display_name', label: 'sales_group.display_name_col', default: true, locked: true },
  { key: 'name', label: 'sales_group.internal_name_col', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
