import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { partners } from '../../api/partners'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'
import ColumnPicker from '../../components/ColumnPicker'
import SortableColumnHeader from '../../components/SortableColumnHeader'
import { useListColumns } from '../../hooks/useListColumns'
import { partnerColumns } from '../../columns/partners'
import { useToast } from '../../contexts/ToastContext'

function renderCell(key, p, { t, can, onDelete }) {
  switch (key) {
    case 'name':
      return <td key="name">{p.name}</td>
    case 'tax_number':
      return <td key="tax_number">{p.tax_number ?? '—'}</td>
    case 'type':
      return <td key="type">{p.type}</td>
    case 'city':
      return <td key="city">{p.billing_city}</td>
    case 'email':
      return <td key="email">{p.email ?? '—'}</td>
    case 'actions':
      return (
        <td key="actions" className="flex">
          {can('partner.edit') && <Link to={`/partners/${p.id}/edit`} className="btn btn-secondary btn-sm">{t('common.edit')}</Link>}
          {can('partner.delete') && <button className="btn btn-danger btn-sm" onClick={() => onDelete(p.id)}>{t('common.delete')}</button>}
        </td>
      )
    default:
      return null
  }
}

export default function PartnerListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const [data, setData]       = useState(null)
  const [search, setSearch]   = useState('')
  const [loading, setLoading] = useState(true)
  const [page, setPage]       = useState(1)
  const [columnsOpen, setColumnsOpen] = useState(false)
  // A lapméret a mentett lista-preferenciából jön (l. useListColumns) — ezért
  // nincs külön useState rá.
  const { allColumns, visibleColumns, isVisible, toggle, reorder, reset, isDirty, pageSize: perPage, setPageSize, sort, toggleSort } =
    useListColumns('partners.index', partnerColumns, { defaultPageSize: 20 })

  async function load(s, pp, pg) {
    setLoading(true)
    try {
      // Rendezés: a mentett preferenciából (nincs saját rendezés → a végpont a
      // saját alapértelmezését adja, l. PartnerController::SORTABLE_COLUMNS).
      const res = await partners.list({
        search: s || undefined, sort_by: sort?.by, sort_dir: sort?.dir, per_page: pp, page: pg,
      })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  // Ez egyben a MOUNT-effekt is: az első lekérdezés már a mentett rendezéssel
  // indul. Rendezésváltáskor vissza az első oldalra (l. DocumentListPage).
  useEffect(() => { setPage(1); load(search, perPage, 1) }, [sort]) // eslint-disable-line react-hooks/exhaustive-deps

  async function handleDelete(id) {
    if (!confirm(t('common.delete') + '?')) return
    try {
      await partners.destroy(id)
      toast(t('partner.deleted'), 'success')
      load(search, perPage, page)
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  function handlePerPage(value) {
    setPageSize(value); setPage(1)
    load(search, value, 1)
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('partner.title')}</h1>
        {can('partner.create') && <Link to="/partners/new" className="btn btn-primary">{t('partner.new')}</Link>}
      </div>
      <form className="search-row" onSubmit={(e) => { e.preventDefault(); setPage(1); load(search, perPage, 1) }}>
        <input placeholder="Név vagy adószám…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="btn btn-secondary" type="submit">{t('common.search')}</button>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggle} onReorder={reorder} onReset={reset} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="partners-columns" align="right"
        />
      </form>
      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <table>
          <thead>
            <tr>
              {visibleColumns.map((col) => (
                col.sortable ? (
                  <SortableColumnHeader
                    key={col.key}
                    label={t(col.label)}
                    align={col.align}
                    direction={sort?.by === col.key ? sort.dir : null}
                    onSort={() => toggleSort(col.key)}
                  />
                ) : (
                  <th key={col.key}>{t(col.label)}</th>
                )
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
