import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { products } from '../../api/products'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'
import { useToast } from '../../contexts/ToastContext'

export default function ProductListPage() {
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
      const res = await products.list({ search: s || undefined, per_page: pp, page: pg })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load('', 20, 1) }, [])

  async function handleDelete(id) {
    if (!confirm(t('common.delete') + '?')) return
    try {
      await products.destroy(id)
      toast(t('product.deleted'), 'success')
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
        <h1 className="page-title">{t('product.title')}</h1>
        {can('product.create') && <Link to="/products/new" className="btn btn-primary">{t('product.new')}</Link>}
      </div>
      <form className="search-row" onSubmit={(e) => { e.preventDefault(); setPage(1); load(search, perPage, 1) }}>
        <input placeholder="Név vagy cikkszám…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="btn btn-secondary" type="submit">{t('common.search')}</button>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
      </form>
      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <table>
          <thead><tr><th>{t('product.sku')}</th><th>{t('product.name')}</th><th>{t('product.unit')}</th><th>Alapár</th><th>{t('product.vat_rate')}</th><th>Típus</th><th></th></tr></thead>
          <tbody>
            {data?.data.map((p) => (
              <tr key={p.id}>
                <td>{p.sku}</td>
                <td>{p.name}</td>
                <td>{p.unit}</td>
                <td className="text-right">{Number(p.base_price).toLocaleString('hu')} {p.base_currency}</td>
                <td>{p.vat_rate?.name}</td>
                <td>{p.type === 'service' ? 'Szolgáltatás' : 'Termék'}</td>
                <td className="flex">
                  {can('product.edit') && <Link to={`/products/${p.id}/edit`} className="btn btn-secondary btn-sm">{t('common.edit')}</Link>}
                  {can('product.delete') && <button className="btn btn-danger btn-sm" onClick={() => handleDelete(p.id)}>{t('common.delete')}</button>}
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
