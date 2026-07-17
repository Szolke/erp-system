import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { groups as groupsApi } from '../../api/groups'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'
import { useToast } from '../../contexts/ToastContext'

export default function GroupListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const [data, setData]         = useState(null)
  const [loading, setLoading]   = useState(true)
  const [perPage, setPerPage]   = useState(20)
  const [page, setPage]         = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm]         = useState({ name: '', description: '' })
  const [formErr, setFormErr]   = useState('')
  const [saving, setSaving]     = useState(false)

  async function load(pp = 20, pg = 1) {
    setLoading(true)
    try {
      const res = await groupsApi.list({ per_page: pp, page: pg })
      setData(res.data)
    } finally { setLoading(false) }
  }

  function handlePerPage(value) {
    setPerPage(value); setPage(1)
    load(value, 1)
  }

  useEffect(() => { load() }, [])

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
      </div>

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>{t('group.group_name')}</th>
              <th>Leírás</th>
              <th>{t('group.members')}</th>
              <th>{t('group.permissions')}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {list.length === 0 && (
              <tr><td colSpan={5} className="text-muted">{t('common.not_found')}</td></tr>
            )}
            {list.map((g) => (
              <tr key={g.id}>
                <td>
                  <Link to={`/groups/${g.id}`} className="table-link">{g.name}</Link>
                  {g.is_system && <span className="badge badge-inv-storno" style={{ marginLeft: 6 }}>rendszer</span>}
                </td>
                <td className="text-muted">{g.description || '—'}</td>
                <td>{g.users_count}</td>
                <td>{g.permissions_count}</td>
                <td style={{ textAlign: 'right' }}>
                  <Link to={`/groups/${g.id}`} className="btn btn-secondary btn-sm" style={{ marginRight: 6 }}>{t('common.edit')}</Link>
                  {canManage && !g.is_system && (
                    <button className="btn btn-danger btn-sm" onClick={() => handleDelete(g)}>{t('common.delete')}</button>
                  )}
                </td>
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
