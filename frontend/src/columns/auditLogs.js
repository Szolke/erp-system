// Audit napló oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const auditLogColumns = [
  { key: 'timestamp', label: 'audit.timestamp_col', default: true, locked: true },
  { key: 'user', label: 'audit.user_col', default: true },
  { key: 'event', label: 'audit.event', default: true },
  { key: 'record', label: 'audit.record', default: true },
  { key: 'before', label: 'audit.before', default: true },
  { key: 'after', label: 'audit.after', default: true },
]
