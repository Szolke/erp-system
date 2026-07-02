import { useEffect, useState } from 'react'
import { company as companyApi } from '../api/company'
import PerPageSelector from '../components/PerPageSelector'
import { useTranslation } from '../contexts/TranslationContext'

export default function AuditLogPage() {
  const { t } = useTranslation()
  const [data, setData]       = useState(null)
  const [action, setAction]   = useState('')
  const [loading, setLoading] = useState(true)
  const [perPage, setPerPage] = useState(50)

  async function load(a, pp) {
    setLoading(true)
    try {
      const res = await companyApi.auditLogs({ action: a || undefined, per_page: pp })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load('', 50) }, [])

  function handlePerPage(value) {
    setPerPage(value)
    load(action, value)
  }

  return (
    <div>
      <div className="page-header"><h1 className="page-title">{t('audit.title')}</h1></div>
      <form className="search-row" onSubmit={(e) => { e.preventDefault(); load(action, perPage) }}>
        <input placeholder="Szűrés művelet szerint (pl. invoice.cancel)" value={action} onChange={(e) => setAction(e.target.value)} />
        <button className="btn btn-secondary" type="submit">{t('common.search')}</button>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
      </form>
      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <table>
          <thead><tr><th>Időpont</th><th>{t('audit.user_col')}</th><th>{t('audit.event')}</th><th>{t('audit.record')}</th><th>{t('audit.before')}</th><th>{t('audit.after')}</th></tr></thead>
          <tbody>
            {data?.data.map((log) => (
              <tr key={log.id}>
                <td style={{ whiteSpace: 'nowrap' }}>{log.created_at}</td>
                <td>{log.user?.name ?? '—'}</td>
                <td><code style={{ fontSize: 11, background: '#f1f5f9', padding: '2px 6px', borderRadius: 3 }}>{log.action}</code></td>
                <td>{log.auditable_type ? `${log.auditable_type.split('\\').pop()}#${log.auditable_id}` : '—'}</td>
                <td style={{ fontSize: 11 }}>{log.old_values ? JSON.stringify(log.old_values) : '—'}</td>
                <td style={{ fontSize: 11 }}>{log.new_values ? JSON.stringify(log.new_values) : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total} {t('common.pieces')}</p>}
    </div>
  )
}
