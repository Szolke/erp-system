import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { users as usersApi } from '../../api/users'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'
import ColumnPicker from '../../components/ColumnPicker'
import { useListColumns } from '../../hooks/useListColumns'
import { userColumns } from '../../columns/users'
import JobPositionSelect from '../../components/JobPositionSelect'
import { useToast } from '../../contexts/ToastContext'

function renderCell(key, u, { t, canManage, onToggleActive, onRemove }) {
  switch (key) {
    case 'name':
      return <td key="name"><Link to={`/users/${u.id}`} className="table-link">{u.name}</Link></td>
    case 'email':
      return <td key="email">{u.email}</td>
    case 'groups':
      return (
        <td key="groups">
          {u.groups?.length
            ? u.groups.map((g) => (
                <span key={g.id} className="badge badge-inv-issued" style={{ marginRight: 4 }}>{g.name}</span>
              ))
            : <span className="text-muted">—</span>}
        </td>
      )
    case 'status':
      return (
        <td key="status">
          {u.is_superadmin
            ? <span className="badge badge-inv-issued">Szuperadmin</span>
            : <span className={u.is_active ? 'badge badge-pay-paid' : 'badge badge-inv-storno'}>
                {u.is_active ? t('common.active') : 'Inaktív'}
              </span>
          }
        </td>
      )
    case 'actions':
      return (
        <td key="actions" style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
          <Link to={`/users/${u.id}`} className="btn btn-secondary btn-sm" style={{ marginRight: 6 }}>{t('user.overrides')}</Link>
          {canManage && !u.is_superadmin && (
            <>
              <button className="btn btn-secondary btn-sm" onClick={() => onToggleActive(u)} style={{ marginRight: 6 }}>
                {u.is_active ? 'Letiltás' : 'Engedélyezés'}
              </button>
              <button className="btn btn-danger btn-sm" onClick={() => onRemove(u)}>{t('group.remove_member')}</button>
            </>
          )}
        </td>
      )
    default:
      return null
  }
}

export default function UserListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const [data, setData]         = useState(null)
  const [search, setSearch]     = useState('')
  const [loading, setLoading]   = useState(true)
  const [perPage, setPerPage]   = useState(20)
  const [page, setPage]         = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [columnsOpen, setColumnsOpen] = useState(false)
  const [form, setForm]         = useState({ name: '', email: '', password: '', job_position_id: null })
  const [formErr, setFormErr]   = useState('')
  const [saving, setSaving]     = useState(false)
  const { allColumns, visibleColumns, isVisible, toggle: toggleColumn, reset: resetColumns, isDirty } = useListColumns('users.index', userColumns)

  async function load(q = '', pp = 20, pg = 1) {
    setLoading(true)
    try {
      const res = await usersApi.list({ search: q || undefined, per_page: pp, page: pg })
      setData(res.data)
    } finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  function handleSearch(e) {
    const v = e.target.value
    setSearch(v); setPage(1)
    clearTimeout(window._userSearchTimer)
    window._userSearchTimer = setTimeout(() => load(v, perPage, 1), 300)
  }

  function handlePerPage(value) {
    setPerPage(value); setPage(1)
    load(search, value, 1)
  }

  async function handleCreate(e) {
    e.preventDefault()
    setFormErr('')
    setSaving(true)
    try {
      await usersApi.create(form)
      setForm({ name: '', email: '', password: '', job_position_id: null })
      setShowForm(false)
      toast(t('common.saved'), 'success')
      load(search, perPage, page)
    } catch (err) {
      const errs = err.response?.data?.errors
      setFormErr(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? t('common.error'))
    } finally { setSaving(false) }
  }

  async function handleToggleActive(user) {
    await usersApi.update(user.id, { is_active: !user.is_active })
    toast(t('common.saved'), 'success')
    load(search, perPage, page)
  }

  async function handleRemove(user) {
    if (!confirm(`Eltávolítja ${user.name} felhasználót a cégtől?`)) return
    try {
      await usersApi.remove(user.id)
      toast(t('common.saved'), 'success')
      load(search, perPage, page)
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  const list = data?.data ?? []
  const canManage = can('user.manage')

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('user.title')}</h1>
        {canManage && (
          <button className="btn btn-primary" onClick={() => setShowForm(!showForm)}>
            {showForm ? t('common.cancel') : t('user.new')}
          </button>
        )}
      </div>

      {showForm && (
        <div className="card">
          <strong>Új felhasználó / meglévő hozzárendelése</strong>
          <p className="text-muted mt-4" style={{ fontSize: 12 }}>
            Ha az e-mail cím már regisztrált, a felhasználó egyszerűen hozzárendelésre kerül ehhez a céghez.
          </p>
          {formErr && <div className="alert-error mt-4">{formErr}</div>}
          <form onSubmit={handleCreate} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr auto', gap: 12, marginTop: 12, alignItems: 'flex-end' }}>
            <div className="form-group" style={{ margin: 0 }}>
              <label>{t('user.name')}</label>
              <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>{t('user.email')}</label>
              <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>{t('user.password')} (elhagyható meglévőnél)</label>
              <input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="min. 8 karakter" />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>Munkakör</label>
              <JobPositionSelect
                value={form.job_position_id}
                onChange={(v) => setForm({ ...form, job_position_id: v })}
              />
            </div>
            <button className="btn btn-primary" type="submit" disabled={saving}>
              {saving ? t('common.saving') : t('common.add')}
            </button>
          </form>
        </div>
      )}

      <div className="search-row">
        <input placeholder="Keresés névben / e-mailben…" value={search} onChange={handleSearch} />
        <PerPageSelector value={perPage} onChange={handlePerPage} />
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggleColumn} onReset={resetColumns} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="users-columns"
        />
      </div>

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <table>
          <thead>
            <tr>
              {visibleColumns.map((col) => (
                <th key={col.key}>{t(col.label)}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {list.length === 0 && (
              <tr><td colSpan={visibleColumns.length} className="text-muted">{t('common.not_found')}</td></tr>
            )}
            {list.map((u) => (
              <tr key={u.id}>
                {visibleColumns.map((col) => renderCell(col.key, u, { t, canManage, onToggleActive: handleToggleActive, onRemove: handleRemove }))}
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
