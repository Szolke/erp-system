import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { assets } from '../../api/assets'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { AssetStatusBadge } from '../../components/StatusBadge'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'

export default function AssetListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const [data, setData]       = useState(null)
  const [search, setSearch]   = useState('')
  const [loading, setLoading] = useState(true)
  const [perPage, setPerPage] = useState(20)
  const [page, setPage]       = useState(1)

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
      </form>
      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <table>
          <thead>
            <tr>
              <th>{t('asset.name')}</th>
              <th>{t('asset.serial_number')}</th>
              <th>{t('asset.imei')}</th>
              <th>{t('asset.asset_type')}</th>
              <th>{t('asset.status')}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {data?.data.length === 0 && (
              <tr><td colSpan={6} className="text-muted">{t('asset.no_assets')}</td></tr>
            )}
            {data?.data.map((a) => (
              <tr key={a.id}>
                <td><code style={{ fontSize: 13 }}>{a.name}</code></td>
                <td>{a.serial_number}</td>
                <td className="text-muted">{a.imei ?? '—'}</td>
                <td>{a.asset_type?.name}</td>
                <td><AssetStatusBadge status={a.status} /></td>
                <td className="flex">
                  {can('asset.edit') && <Link to={`/assets/${a.id}/edit`} className="btn btn-secondary btn-sm">{t('common.edit')}</Link>}
                  {can('asset.delete') && <button className="btn btn-danger btn-sm" onClick={() => handleDelete(a)}>{t('common.delete')}</button>}
                </td>
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
