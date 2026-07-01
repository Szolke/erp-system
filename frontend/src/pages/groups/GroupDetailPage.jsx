import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import client from '../../api/client'
import { groups as groupsApi } from '../../api/groups'
import { useAuth } from '../../contexts/AuthContext'

export default function GroupDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const [group, setGroup]           = useState(null)
  const [allPerms, setAllPerms]     = useState([])
  const [companyUsers, setCompanyUsers] = useState([])
  const [loading, setLoading]       = useState(true)
  const [saving, setSaving]         = useState(false)
  const [selectedPerms, setSelectedPerms] = useState(new Set())
  const [addUserId, setAddUserId]   = useState('')
  const [error, setError]           = useState('')

  async function load() {
    const [gRes, pRes, uRes] = await Promise.all([
      groupsApi.get(id),
      client.get('/api/permissions'),
      client.get('/api/users', { params: { per_page: 200 } }),
    ])
    const g = gRes.data
    setGroup(g)
    setAllPerms(pRes.data.data ?? [])
    setCompanyUsers(uRes.data.data ?? uRes.data ?? [])
    setSelectedPerms(new Set(g.permissions?.map((p) => p.id) ?? []))
    setLoading(false)
  }

  useEffect(() => { load() }, [id])

  function togglePerm(permId) {
    setSelectedPerms((prev) => {
      const next = new Set(prev)
      next.has(permId) ? next.delete(permId) : next.add(permId)
      return next
    })
  }

  async function handleSavePerms() {
    setSaving(true)
    setError('')
    try {
      await groupsApi.syncPermissions(id, [...selectedPerms])
      await load()
    } catch (err) {
      setError(err.response?.data?.message ?? 'Hiba')
    } finally { setSaving(false) }
  }

  async function handleAddMember(e) {
    e.preventDefault()
    if (!addUserId) return
    setError('')
    try {
      await groupsApi.addMember(id, Number(addUserId))
      setAddUserId('')
      load()
    } catch (err) {
      setError(err.response?.data?.message ?? 'Hiba')
    }
  }

  async function handleRemoveMember(userId) {
    await groupsApi.removeMember(id, userId)
    load()
  }

  const canManage = can('group.manage')

  if (loading) return <p className="text-muted">Betöltés…</p>
  if (!group) return <p className="text-muted">Nem található.</p>

  // Jogosultságok modulonként csoportosítva
  const byModule = allPerms.reduce((acc, p) => {
    ;(acc[p.module] ??= []).push(p)
    return acc
  }, {})

  const memberIds = new Set(group.users?.map((u) => u.id) ?? [])
  const nonMembers = companyUsers.filter((u) => !memberIds.has(u.id))

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">{group.name}</h1>
          {group.description && <p className="text-muted mt-4">{group.description}</p>}
        </div>
        <Link to="/groups" className="btn btn-secondary">← Vissza</Link>
      </div>

      {error && <div className="alert-error mb-4">{error}</div>}

      {/* ── Jogosultságok ── */}
      <div className="card">
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
          <strong>Jogosultságok</strong>
          {canManage && !group.is_system && (
            <button className="btn btn-primary btn-sm" onClick={handleSavePerms} disabled={saving}>
              {saving ? 'Mentés…' : 'Mentés'}
            </button>
          )}
        </div>
        {group.is_system && <p className="text-muted" style={{ fontSize: 12, marginBottom: 12 }}>Rendszer-csoport jogosultságai nem módosíthatók.</p>}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 20 }}>
          {Object.entries(byModule).map(([module, perms]) => (
            <div key={module}>
              <div style={{ fontWeight: 700, fontSize: 12, textTransform: 'uppercase', color: 'var(--color-muted)', marginBottom: 8 }}>{module}</div>
              {perms.map((p) => (
                <label key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6, cursor: canManage && !group.is_system ? 'pointer' : 'default' }}>
                  <input
                    type="checkbox"
                    checked={selectedPerms.has(p.id)}
                    onChange={() => togglePerm(p.id)}
                    disabled={!canManage || group.is_system}
                    style={{ width: 'auto' }}
                  />
                  <span style={{ fontSize: 13 }}>{p.description}</span>
                  {p.is_sensitive && <span className="badge badge-inv-storno" style={{ fontSize: 10 }}>érzékeny</span>}
                </label>
              ))}
            </div>
          ))}
        </div>
      </div>

      {/* ── Tagok ── */}
      <div className="card">
        <strong>Tagok</strong>

        {canManage && (
          <form onSubmit={handleAddMember} style={{ display: 'flex', gap: 10, marginTop: 12, marginBottom: 16 }}>
            <select value={addUserId} onChange={(e) => setAddUserId(e.target.value)} required style={{ maxWidth: 320 }}>
              <option value="">— felhasználó hozzáadása —</option>
              {nonMembers.map((u) => (
                <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
              ))}
            </select>
            <button className="btn btn-primary btn-sm" type="submit" disabled={!addUserId}>Hozzáadás</button>
            {nonMembers.length === 0 && companyUsers.length > 0 && (
              <span className="text-muted" style={{ fontSize: 12, alignSelf: 'center' }}>
                Minden céges felhasználó már tagja ennek a csoportnak.
              </span>
            )}
          </form>
        )}

        {group.users?.length === 0 && <p className="text-muted">Nincs tag.</p>}
        {group.users?.length > 0 && (
          <table>
            <thead>
              <tr><th>Név</th><th>E-mail</th>{canManage && <th></th>}</tr>
            </thead>
            <tbody>
              {group.users.map((u) => (
                <tr key={u.id}>
                  <td><a href={`/users/${u.id}`} className="table-link">{u.name}</a></td>
                  <td>{u.email}</td>
                  {canManage && (
                    <td style={{ textAlign: 'right' }}>
                      <button className="btn btn-danger btn-sm" onClick={() => handleRemoveMember(u.id)}>Eltávolítás</button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
