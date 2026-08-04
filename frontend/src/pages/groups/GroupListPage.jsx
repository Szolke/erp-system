import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { groups as groupsApi } from '../../api/groups'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'
import ColumnPicker from '../../components/ColumnPicker'
import SortableColumnHeader from '../../components/SortableColumnHeader'
import { useListColumns } from '../../hooks/useListColumns'
import { groupColumns } from '../../columns/groups'
import { useToast } from '../../contexts/ToastContext'

function renderCell(key, g, { t, canManage, onDelete }) {
  switch (key) {
    case 'name':
      return (
        <td key="name">
          <Link to={`/groups/${g.id}`} className="table-link">{g.name}</Link>
          {g.is_system && <span className="badge badge-inv-storno" style={{ marginLeft: 6 }}>rendszer</span>}
        </td>
      )
    case 'description':
      return <td key="description" className="text-muted">{g.description || '—'}</td>
    case 'members':
      return <td key="members">{g.users_count}</td>
    case 'permissions':
      return <td key="permissions">{g.permissions_count}</td>
    case 'actions':
      return (
        <td key="actions" style={{ textAlign: 'right' }}>
          <Link to={`/groups/${g.id}`} className="btn btn-secondary btn-sm" style={{ marginRight: 6 }}>{t('common.edit')}</Link>
          {canManage && !g.is_system && (
            <button className="btn btn-danger btn-sm" onClick={() => onDelete(g)}>{t('common.delete')}</button>
          )}
        </td>
      )
    default:
      return null
  }
}

export default function GroupListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const [data, setData]         = useState(null)
  const [loading, setLoading]   = useState(true)
  const [page, setPage]         = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [columnsOpen, setColumnsOpen] = useState(false)
  const [form, setForm]         = useState({ name: '', description: '' })
  const [formErr, setFormErr]   = useState('')
  const [saving, setSaving]     = useState(false)
  // A lapméret a mentett lista-preferenciából jön (l. useListColumns) — ezért
  // nincs külön useState rá, és a load() alapértéke is innen származik.
  const { allColumns, visibleColumns, isVisible, toggle: toggleColumn, reorder, reset: resetColumns, isDirty, pageSize: perPage, setPageSize, sort, toggleSort } =
    useListColumns('groups.index', groupColumns, { defaultPageSize: 20 })

  async function load(pp = perPage, pg = 1) {
    setLoading(true)
    try {
      // Rendezés: a mentett preferenciából (nincs saját rendezés → a végpont a
      // saját alapértelmezését adja, l. GroupController::SORTABLE_COLUMNS).
      const res = await groupsApi.list({ sort_by: sort?.by, sort_dir: sort?.dir, per_page: pp, page: pg })
      setData(res.data)
    } finally { setLoading(false) }
  }

  function handlePerPage(value) {
    setPageSize(value); setPage(1)
    load(value, 1)
  }

  // Ez egyben a MOUNT-effekt is: az első lekérdezés már a mentett rendezéssel
  // indul. Rendezésváltáskor vissza az első oldalra (l. DocumentListPage).
  useEffect(() => { setPage(1); load(perPage, 1) }, [sort]) // eslint-disable-line react-hooks/exhaustive-deps

  async function handleCreate(e) {
    e.preventDefault()
    setFormErr('')
    setSaving(true)
    try {
      await groupsApi.create(form)
      setForm({ name: '', description: '' })
      setShowForm(false)
      toast(t('common.saved'), 'success')
      load(perPage, page)
    } catch (err) {
      const errs = err.response?.data?.errors
      setFormErr(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? t('common.error'))
    } finally { setSaving(false) }
  }

  async function handleDelete(group) {
    if (!confirm(`Törli a(z) „${group.name}" csoportot?`)) return
    try {
      await groupsApi.remove(group.id)
      toast(t('group.deleted'), 'success')
      load(perPage, page)
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  const list = data?.data ?? []
  const canManage = can('group.manage')

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('group.title')}</h1>
        {canManage && (
          <button className="btn btn-primary" onClick={() => setShowForm(!showForm)}>
            {showForm ? t('common.cancel') : t('group.new')}
          </button>
        )}
      </div>

      {showForm && (
        <div className="card">
          <strong>{t('group.new')}</strong>
          {formErr && <div className="alert-error mt-4">{formErr}</div>}
          <form onSubmit={handleCreate} style={{ display: 'grid', gridTemplateColumns: '1fr 2fr auto', gap: 12, marginTop: 12, alignItems: 'flex-end' }}>
            <div className="form-group" style={{ margin: 0 }}>
              <label>{t('group.group_name')}</label>
              <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>Leírás</label>
              <input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <button className="btn btn-primary" type="submit" disabled={saving}>
              {saving ? t('common.saving') : 'Létrehozás'}
            </button>
          </form>
        </div>
      )}

      <div className="search-row">
        <PerPageSelector value={perPage} onChange={handlePerPage} />
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggleColumn} onReorder={reorder} onReset={resetColumns} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="groups-columns" align="right"
        />
      </div>

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
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
            {list.length === 0 && (
              <tr><td colSpan={visibleColumns.length} className="text-muted">{t('common.not_found')}</td></tr>
            )}
            {list.map((g) => (
              <tr key={g.id}>
                {visibleColumns.map((col) => renderCell(col.key, g, { t, canManage, onDelete: handleDelete }))}
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total} {t('common.pieces')}</p>}
      <Pagination meta={data?.meta} onChange={(p) => { setPage(p); load(perPage, p) }} />
    </div>
  )
}
