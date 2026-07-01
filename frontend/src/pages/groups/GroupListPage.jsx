import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { groups as groupsApi } from '../../api/groups'
import PerPageSelector from '../../components/PerPageSelector'

export default function GroupListPage() {
  const { can } = useAuth()
  const [list, setList]         = useState([])
  const [loading, setLoading]   = useState(true)
  const [perPage, setPerPage]   = useState(20)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm]         = useState({ name: '', description: '' })
  const [formErr, setFormErr]   = useState('')
  const [saving, setSaving]     = useState(false)

  async function load(pp = 20) {
    setLoading(true)
    try {
      const res = await groupsApi.list({ per_page: pp })
      setList(res.data.data ?? [])
    } finally { setLoading(false) }
  }

  function handlePerPage(value) {
    setPerPage(value)
    load(value)
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
      load(perPage)
    } catch (err) {
      const errs = err.response?.data?.errors
      setFormErr(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? 'Hiba')
    } finally { setSaving(false) }
  }

  async function handleDelete(group) {
    if (!confirm(`Törli a(z) „${group.name}" csoportot?`)) return
    try {
      await groupsApi.remove(group.id)
      load(perPage)
    } catch (err) {
      alert(err.response?.data?.message ?? 'Hiba')
    }
  }

  const canManage = can('group.manage')

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Csoportok</h1>
        {canManage && (
          <button className="btn btn-primary" onClick={() => setShowForm(!showForm)}>
            {showForm ? 'Mégsem' : '+ Új csoport'}
          </button>
        )}
      </div>

      {showForm && (
        <div className="card">
          <strong>Új csoport</strong>
          {formErr && <div className="alert-error mt-4">{formErr}</div>}
          <form onSubmit={handleCreate} style={{ display: 'grid', gridTemplateColumns: '1fr 2fr auto', gap: 12, marginTop: 12, alignItems: 'flex-end' }}>
            <div className="form-group" style={{ margin: 0 }}>
              <label>Név</label>
              <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>Leírás</label>
              <input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <button className="btn btn-primary" type="submit" disabled={saving}>
              {saving ? 'Mentés…' : 'Létrehozás'}
            </button>
          </form>
        </div>
      )}

      <div className="search-row">
        <PerPageSelector value={perPage} onChange={handlePerPage} />
      </div>

      {loading ? (
        <p className="text-muted">Betöltés…</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Csoport neve</th>
              <th>Leírás</th>
              <th>Tagok</th>
              <th>Jogosultságok</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {list.length === 0 && (
              <tr><td colSpan={5} className="text-muted">Nincs csoport.</td></tr>
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
                  <Link to={`/groups/${g.id}`} className="btn btn-secondary btn-sm" style={{ marginRight: 6 }}>Szerkesztés</Link>
                  {canManage && !g.is_system && (
                    <button className="btn btn-danger btn-sm" onClick={() => handleDelete(g)}>Törlés</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
