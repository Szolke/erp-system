// Munkakör-lista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const jobPositionColumns = [
  { key: 'name', label: 'common.name', default: true, locked: true },
  { key: 'scope', label: 'job_position.scope_col', default: true },
  { key: 'status', label: 'job_position.status_col', default: true },
  { key: 'sort_order', label: 'job_position.sort_order_col', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
