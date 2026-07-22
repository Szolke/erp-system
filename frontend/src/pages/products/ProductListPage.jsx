import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { products } from '../../api/products'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'
import ColumnPicker from '../../components/ColumnPicker'
import { useListColumns } from '../../hooks/useListColumns'
import { productColumns } from '../../columns/products'
import { useToast } from '../../contexts/ToastContext'

function renderCell(key, p, { t, can, onDelete }) {
  switch (key) {
    case 'sku':
      return <td key="sku">{p.sku}</td>
    case 'name':
      return <td key="name">{p.name}</td>
    case 'unit':
      return <td key="unit">{p.unit}</td>
    case 'base_price':
      return <td key="base_price" className="text-right">{Number(p.base_price).toLocaleString('hu')} {p.base_currency}</td>
    case 'vat_rate':
      return <td key="vat_rate">{p.vat_rate?.name}</td>
    case 'type':
      return <td key="type">{p.type === 'service' ? 'Szolgáltatás' : 'Termék'}</td>
    case 'actions':
      return (
        <td key="actions" className="flex">
          {can('product.edit') && <Link to={`/products/${p.id}/edit`} className="btn btn-secondary btn-sm">{t('common.edit')}</Link>}
          {can('product.delete') && <button className="btn btn-danger btn-sm" onClick={() => onDelete(p.id)}>{t('common.delete')}</button>}
        </td>
      )
    default:
      return null
  }
}

export default function ProductListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const [data, setData]       = useState(null)
  const [search, setSearch]   = useState('')
  const [loading, setLoading] = useState(true)
  const [perPage, setPerPage] = useState(20)
  const [page, setPage]       = useState(1)
  const [columnsOpen, setColumnsOpen] = useState(false)
  const { allColumns, visibleColumns, isVisible, toggle, reorder, reset, isDirty } = useListColumns('products.index', productColumns)

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
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggle} onReorder={reorder} onReset={reset} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="products-columns" align="right"
        />
      </form>
      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <table>
          <thead>
            <tr>
              {visibleColumns.map((col) => (
                <th key={col.key} className={col.align === 'right' ? 'text-right' : undefined}>{t(col.label)}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data?.data.map((p) => (
              <tr key={p.id}>
                {visibleColumns.map((col) => renderCell(col.key, p, { t, can, onDelete: handleDelete }))}
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
