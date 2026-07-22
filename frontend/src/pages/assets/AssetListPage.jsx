import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { assets } from '../../api/assets'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { AssetStatusBadge } from '../../components/StatusBadge'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'
import ColumnPicker from '../../components/ColumnPicker'
import { useListColumns } from '../../hooks/useListColumns'
import { assetColumns } from '../../columns/assets'

function renderCell(key, a, { t, can, onDelete }) {
  switch (key) {
    case 'name':
      return <td key="name"><code style={{ fontSize: 13 }}>{a.name}</code></td>
    case 'serial_number':
      return <td key="serial_number">{a.serial_number}</td>
    case 'imei':
      return <td key="imei" className="text-muted">{a.imei ?? '—'}</td>
    case 'asset_type':
      return <td key="asset_type">{a.asset_type?.name}</td>
    case 'status':
      return <td key="status"><AssetStatusBadge status={a.status} /></td>
    case 'actions':
      return (
        <td key="actions" className="flex">
          {can('asset.edit') && <Link to={`/assets/${a.id}/edit`} className="btn btn-secondary btn-sm">{t('common.edit')}</Link>}
          {can('asset.delete') && <button className="btn btn-danger btn-sm" onClick={() => onDelete(a)}>{t('common.delete')}</button>}
        </td>
      )
    default:
      return null
  }
}

export default function AssetListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const [data, setData]       = useState(null)
  const [search, setSearch]   = useState('')
  const [loading, setLoading] = useState(true)
  const [perPage, setPerPage] = useState(20)
  const [page, setPage]       = useState(1)
  const [columnsOpen, setColumnsOpen] = useState(false)
  const { allColumns, visibleColumns, isVisible, toggle, reset, isDirty } = useListColumns('assets.index', assetColumns)

  async function load(s, pp, pg) {
    setLoading(true)
    try {
      const res = await assets.list({ search: s || undefined, per_page: pp, page: pg })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load('', 20, 1) }, [])

  async function handleDelete(asset) {
    if (!confirm(`${t('common.delete')}: ${asset.name}?`)) return
    try {
      await assets.destroy(asset.id)
      toast(t('asset.deleted'), 'success')
      load(search, perPage, page)
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  function handlePerPage(value) {
    setPerPage(value); setPage(1)
    load(search, value, 1)
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('asset.title')}</h1>
        {can('asset.create') && <Link to="/assets/new" className="btn btn-primary">{t('asset.new')}</Link>}
      </div>
      <form className="search-row" onSubmit={(e) => { e.preventDefault(); setPage(1); load(search, perPage, 1) }}>
        <input placeholder={t('asset.search_placeholder')} value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="btn btn-secondary" type="submit">{t('common.search')}</button>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggle} onReset={reset} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="assets-columns"
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
            {data?.data.length === 0 && (
              <tr><td colSpan={visibleColumns.length} className="text-muted">{t('asset.no_assets')}</td></tr>
            )}
            {data?.data.map((a) => (
              <tr key={a.id}>
                {visibleColumns.map((col) => renderCell(col.key, a, { t, can, onDelete: handleDelete }))}
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total} {t('common.pieces')}</p>}
      <Pagination meta={data?.meta} onChange={(p) => { setPage(p); load(search, perPage, p) }} />
    </div>
  )
}
