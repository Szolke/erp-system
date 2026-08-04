// Audit napló oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: csak az első három oszlop; a kulcsok 1:1-ben megfelelnek a backend
// whitelistjének (AuditLogController::SORTABLE_COLUMNS) — a `user` ott a
// kapcsolt `users.name`-re képződik le (leftJoin). A `record`, `before` és
// `after` oszlop SZÁNDÉKOSAN nem rendezhető: a `record` két mezőből (típus + id)
// összefűzött, megjelenítéskor képzett érték, a `before`/`after` pedig nyers
// jsonb diff — egyik sem képződik le értelmesen rendezhető DB-oszlopra.
export const auditLogColumns = [
  { key: 'timestamp', label: 'audit.timestamp_col', default: true, locked: true, sortable: true, sortInitialDir: 'desc' },
  { key: 'user', label: 'audit.user_col', default: true, sortable: true },
  { key: 'event', label: 'audit.event', default: true, sortable: true },
  { key: 'record', label: 'audit.record', default: true },
  { key: 'before', label: 'audit.before', default: true },
  { key: 'after', label: 'audit.after', default: true },
]
