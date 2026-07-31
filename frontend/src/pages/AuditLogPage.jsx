import { useEffect, useState } from 'react'
import { company as companyApi } from '../api/company'
import PerPageSelector from '../components/PerPageSelector'
import Pagination from '../components/Pagination'
import ColumnPicker from '../components/ColumnPicker'
import { useListColumns } from '../hooks/useListColumns'
import { auditLogColumns } from '../columns/auditLogs'
import { useTranslation } from '../contexts/TranslationContext'

function renderCell(key, log) {
  switch (key) {
    case 'timestamp':
      return <td key="timestamp" style={{ whiteSpace: 'nowrap' }}>{log.created_at}</td>
    case 'user':
      return <td key="user">{log.user?.name ?? '—'}</td>
    case 'event':
      return (
        <td key="event">
          <code style={{ fontSize: 11, background: 'var(--color-surface-alt)', color: 'var(--color-text)', padding: '2px 6px', borderRadius: 3 }}>{log.action}</code>
        </td>
      )
    case 'record':
      return <td key="record">{log.auditable_type ? `${log.auditable_type.split('\\').pop()}#${log.auditable_id}` : '—'}</td>
    case 'before':
      return <td key="before" style={{ fontSize: 11 }}>{log.old_values ? JSON.stringify(log.old_values) : '—'}</td>
    case 'after':
      return <td key="after" style={{ fontSize: 11 }}>{log.new_values ? JSON.stringify(log.new_values) : '—'}</td>
    default:
      return null
  }
}

export default function AuditLogPage() {
  const { t } = useTranslation()
  const [data, setData]       = useState(null)
  const [action, setAction]   = useState('')
  const [loading, setLoading] = useState(true)
  const [page, setPage]       = useState(1)
  const [columnsOpen, setColumnsOpen] = useState(false)
  // A lapméret a mentett lista-preferenciából jön (l. useListColumns); az
  // audit-napló alapértéke a többi listáétól eltérően 50.
  const { allColumns, visibleColumns, isVisible, toggle: toggleColumn, reorder, reset: resetColumns, isDirty, pageSize: perPage, setPageSize } =
    useListColumns('audit_logs.index', auditLogColumns, { defaultPageSize: 50 })

  async function load(a, pp, pg) {
    setLoading(true)
    try {
      const res = await companyApi.auditLogs({ action: a || undefined, per_page: pp, page: pg })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load('', perPage, 1) }, []) // eslint-disable-line react-hooks/exhaustive-deps

  function handlePerPage(value) {
    setPageSize(value); setPage(1)
    load(action, value, 1)
  }

  return (
    <div>
      <div className="page-header"><h1 className="page-title">{t('audit.title')}</h1></div>
      <form className="search-row" onSubmit={(e) => { e.preventDefault(); setPage(1); load(action, perPage, 1) }}>
        <input placeholder="Szűrés művelet szerint (pl. invoice.cancel)" value={action} onChange={(e) => setAction(e.target.value)} />
        <button className="btn btn-secondary" type="submit">{t('common.search')}</button>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggleColumn} onReorder={reorder} onReset={resetColumns} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="audit-logs-columns" align="right"
        />
      </form>
      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <table>
          <thead>
            <tr>
              {visibleColumns.map((col) => (
                <th key={col.key}>{t(col.label)}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data?.data.map((log) => (
              <tr key={log.id}>
                {visibleColumns.map((col) => renderCell(col.key, log))}
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total} {t('common.pieces')}</p>}
      <Pagination meta={data?.meta} onChange={(p) => { setPage(p); load(action, perPage, p) }} />
    </div>
  )
}
