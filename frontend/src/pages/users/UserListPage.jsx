import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { users as usersApi } from '../../api/users'

export default function UserListPage() {
  const { can } = useAuth()
  const [list, setList]       = useState([])
  const [search, setSearch]   = useState('')
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm]       = useState({ name: '', email: '', password: '' })
  const [formErr, setFormErr] = useState('')
  const [saving, setSaving]   = useState(false)

  async function load(q = '') {
    setLoading(true)
    try {
      const res = await usersApi.list(q ? { search: q } : undefined)
      setList(res.data.data ?? res.data ?? [])
    } finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  function handleSearch(e) {
    const v = e.target.value
    setSearch(v)
    clearTimeout(window._userSearchTimer)
    window._userSearchTimer = setTimeout(() => load(v), 300)
  }

  async function handleCreate(e) {
    e.preventDefault()
    setFormErr('')
    setSaving(true)
    try {
      await usersApi.create(form)
      setForm({ name: '', email: '', password: '' })
      setShowForm(false)
      load(search)
    } catch (err) {
      const errs = err.response?.data?.errors
      setFormErr(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? 'Hiba')
    } finally { setSaving(false) }
  }

  async function handleToggleActive(user) {
    await usersApi.update(user.id, { is_active: !user.is_active })
    load(search)
  }

  async function handleRemove(user) {
    if (!confirm(`Eltávolítja ${user.name} felhasználót a cégtől?`)) return
    await usersApi.remove(user.id)
    load(search)
  }

  const canManage = can('user.manage')

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Felhasználók</h1>
        {canManage && (
          <button className="btn btn-primary" onClick={() => setShowForm(!showForm)}>
            {showForm ? 'Mégsem' : '+ Felhasználó hozzáadása'}
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
          <form onSubmit={handleCreate} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: 12, marginTop: 12, alignItems: 'flex-end' }}>
            <div className="form-group" style={{ margin: 0 }}>
              <label>Név</label>
              <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>E-mail</label>
              <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
            </div>
            <div className="form-group" style={{ margin: 0 }}>
              <label>Jelszó (elhagyható meglévőnél)</label>
              <input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="min. 8 karakter" />
            </div>
            <button className="btn btn-primary" type="submit" disabled={saving}>
              {saving ? 'Mentés…' : 'Hozzáad'}
            </button>
          </form>
        </div>
      )}

      <div className="search-row">
        <input placeholder="Keresés névben / e-mailben…" value={search} onChange={handleSearch} />
      </div>

      {loading ? (
        <p className="text-muted">Betöltés…</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Név</th>
              <th>E-mail</th>
              <th>Csoportok</th>
              <th>Státusz</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {list.length === 0 && (
              <tr><td colSpan={5} className="text-muted">Nincs találat.</td></tr>
            )}
            {list.map((u) => (
              <tr key={u.id}>
                <td><Link to={`/users/${u.id}`} className="table-link">{u.name}</Link></td>
                <td>{u.email}</td>
                <td>
                  {u.groups?.length
                    ? u.groups.map((g) => (
                        <span key={g.id} className="badge badge-inv-issued" style={{ marginRight: 4 }}>{g.name}</span>
                      ))
                    : <span className="text-muted">—</span>}
                </td>
                <td>
                  <span className={u.is_active ? 'badge badge-pay-paid' : 'badge badge-inv-storno'}>
                    {u.is_active ? 'Aktív' : 'Inaktív'}
                  </span>
                </td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <Link to={`/users/${u.id}`} className="btn btn-secondary btn-sm" style={{ marginRight: 6 }}>Jogosultságok</Link>
                  {canManage && (
                    <>
                      <button className="btn btn-secondary btn-sm" onClick={() => handleToggleActive(u)} style={{ marginRight: 6 }}>
                        {u.is_active ? 'Letiltás' : 'Engedélyezés'}
                      </button>
                      <button className="btn btn-danger btn-sm" onClick={() => handleRemove(u)}>Eltávolítás</button>
                    </>
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
