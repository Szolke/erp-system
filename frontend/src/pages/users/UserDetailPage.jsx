import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import client from '../../api/client'
import { users as usersApi } from '../../api/users'
import { groups as groupsApi } from '../../api/groups'
import { companies as companiesApi } from '../../api/company'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'

const EFFECT_LABELS = {
  allow: { label: 'Engedélyezve', bg: 'var(--color-success-bg)', color: 'var(--color-success-text)' },
  deny:  { label: 'Tiltva',       bg: 'var(--color-danger-bg)',  color: 'var(--color-danger)' },
  null:  { label: 'Nincs felülírás', bg: 'var(--color-surface-alt)', color: 'var(--color-muted)' },
}

function EffectToggle({ effect, onChange, disabled }) {
  const states = ['allow', 'deny', null]
  function cycle() {
    if (disabled) return
    const next = states[(states.indexOf(effect) + 1) % states.length]
    onChange(next)
  }
  const s = EFFECT_LABELS[effect] ?? EFFECT_LABELS['null']
  return (
    <button
      type="button"
      onClick={cycle}
      disabled={disabled}
      style={{
        background: s.bg, color: s.color,
        border: `1px solid ${s.color}40`,
        borderRadius: 4, padding: '2px 10px', fontSize: 12, fontWeight: 600,
        cursor: disabled ? 'default' : 'pointer', whiteSpace: 'nowrap',
      }}
    >
      {s.label}
    </button>
  )
}

export default function UserDetailPage() {
  const { id } = useParams()
  const { user: authUser, can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const isSuperadmin = authUser?.is_superadmin

  const [data, setData]           = useState(null)
  const [allPerms, setAllPerms]   = useState([])
  const [allGroups, setAllGroups] = useState([])
  const [overrides, setOverrides] = useState({})
  const [loading, setLoading]     = useState(true)
  const [saving, setSaving]       = useState(false)
  const [saved, setSaved]         = useState(false)
  const [error, setError]         = useState('')
  const [addGroupId, setAddGroupId] = useState('')

  // Cég-szekció state (csak superadminnak)
  const [userCompanies, setUserCompanies] = useState([])
  const [allCompanies,  setAllCompanies]  = useState([])
  const [addCompanyId,  setAddCompanyId]  = useState('')

  async function load() {
    const promises = [
      usersApi.get(id),
      client.get('/api/permissions'),
      groupsApi.list(),
    ]
    if (isSuperadmin) {
      promises.push(usersApi.listCompanies(id))
      promises.push(companiesApi.list({ per_page: 200 }))
    }
    const [uRes, pRes, gRes, ucRes, acRes] = await Promise.all(promises)
    const d = uRes.data
    setData(d)
    setAllPerms(pRes.data.data ?? [])
    setAllGroups(gRes.data.data ?? [])
    const map = {}
    Object.entries(d.overrides ?? {}).forEach(([k, v]) => { map[Number(k)] = v })
    setOverrides(map)
    if (isSuperadmin) {
      setUserCompanies(ucRes?.data?.data ?? [])
      setAllCompanies(acRes?.data?.data ?? [])
    }
    setLoading(false)
  }

  useEffect(() => { load() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  function setOverride(permId, effect) {
    setOverrides((prev) => ({ ...prev, [permId]: effect }))
    setSaved(false)
  }

  async function handleSave() {
    setSaving(true)
    setError('')
    setSaved(false)
    try {
      // null értékek is elküldendők (override törlés)
      const payload = {}
      allPerms.forEach((p) => { payload[p.id] = overrides[p.id] ?? null })
      const res = await usersApi.syncOverrides(id, payload)
      const d = res.data
      setData(d)
      const map = {}
      Object.entries(d.overrides ?? {}).forEach(([k, v]) => { map[Number(k)] = v })
      setOverrides(map)
      setSaved(true)
    } catch (err) {
      setError(err.response?.data?.message ?? 'Hiba a mentés során.')
    } finally { setSaving(false) }
  }

  async function handleAddGroup(e) {
    e.preventDefault()
    if (!addGroupId) return
    setError('')
    try {
      await groupsApi.addMember(addGroupId, Number(id))
      setAddGroupId('')
      load()
    } catch (err) {
      setError(err.response?.data?.message ?? 'Hiba')
    }
  }

  async function handleRemoveGroup(groupId) {
    setError('')
    try {
      await groupsApi.removeMember(groupId, Number(id))
      load()
    } catch (err) {
      setError(err.response?.data?.message ?? 'Hiba')
    }
  }

  async function handleAttachCompany(e) {
    e.preventDefault()
    if (!addCompanyId) return
    try {
      const res = await usersApi.attachCompany(id, addCompanyId)
      setUserCompanies(res.data.data ?? [])
      setAddCompanyId('')
      toast(t('user.company_added'), 'success')
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  async function handleDetachCompany(companyId) {
    const company = userCompanies.find((c) => c.id === companyId)
    if (!window.confirm(`Biztosan eltávolítod a(z) „${company?.name ?? companyId}" céget erről a felhasználóról?`)) return
    try {
      await usersApi.detachCompany(id, companyId)
      const res = await usersApi.listCompanies(id)
      setUserCompanies(res.data.data ?? [])
      toast(t('user.company_removed'), 'success')
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  if (loading) return <p className="text-muted">{t('common.loading')}</p>
  if (!data) return <p className="text-muted">{t('common.not_found')}</p>

  const { user, from_groups: fromGroups } = data
  const fromGroupSet = new Set(fromGroups ?? [])
  const canManage   = can('group.manage')
  const canOverride = can('permission.override')

  // Cég-szekció: allCompanies mínusz már hozzárendelt cégek
  const assignedCompanyIds   = new Set(userCompanies.map((c) => c.id))
  const availableCompanies   = allCompanies.filter((c) => !assignedCompanyIds.has(c.id))

  // Modulonként csoportosítva
  const byModule = allPerms.reduce((acc, p) => {
    ;(acc[p.module] ??= []).push(p)
    return acc
  }, {})

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">{user.name}</h1>
          <p className="text-muted">{user.email}</p>
        </div>
        <div className="flex">
          {canOverride && (
            <button className="btn btn-primary" onClick={handleSave} disabled={saving}>
              {saving ? t('common.saving') : t('user.save_overrides')}
            </button>
          )}
          <Link to="/users" className="btn btn-secondary">{t('common.back')}</Link>
        </div>
      </div>

      {error && <div className="alert-error mb-4">{error}</div>}
      {saved && (
        <div className="alert-success" style={{ fontSize: 13, marginBottom: 16 }}>
          Felülírások mentve.
        </div>
      )}

      {/* Cégek — csak superadmin látja */}
      {isSuperadmin && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong>{t('user.companies_section')}</strong>

          {userCompanies.length === 0 && (
            <div style={{
              marginTop: 12, padding: '10px 14px',
              background: 'var(--color-danger-bg)', borderRadius: 6,
              borderLeft: '3px solid var(--color-danger)', fontSize: 13,
            }}>
              <p style={{ margin: 0, fontWeight: 600, color: 'var(--color-danger)' }}>
                {t('user.no_companies')}
              </p>
              <p style={{ margin: '4px 0 0', color: 'var(--color-muted)', fontSize: 12 }}>
                {t('user.no_companies_hint')}
              </p>
            </div>
          )}

          {availableCompanies.length > 0 && (
            <form onSubmit={handleAttachCompany} style={{ display: 'flex', gap: 10, marginTop: 12, marginBottom: 12 }}>
              <select value={addCompanyId} onChange={(e) => setAddCompanyId(e.target.value)} required style={{ maxWidth: 320 }}>
                <option value="">— {t('user.add_company')} —</option>
                {availableCompanies.map((c) => (
                  <option key={c.id} value={c.id}>{c.name} ({c.tax_number})</option>
                ))}
              </select>
              <button className="btn btn-primary btn-sm" type="submit">{t('common.add')}</button>
            </form>
          )}

          {userCompanies.length > 0 && (
            <table style={{ marginTop: 8 }}>
              <thead>
                <tr>
                  <th>{t('company.name')}</th>
                  <th>{t('company.tax_number')}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {userCompanies.map((c) => (
                  <tr key={c.id}>
                    <td><strong>{c.name}</strong></td>
                    <td className="text-muted">{c.tax_number}</td>
                    <td style={{ textAlign: 'right' }}>
                      <button
                        className="btn btn-danger btn-sm"
                        onClick={() => handleDetachCompany(c.id)}
                      >
                        {t('group.remove_member')}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {/* Csoportok */}
      <div className="card" style={{ marginBottom: 16 }}>
        <strong>{t('group.members')}</strong>

        {canManage && (() => {
          const memberGroupIds = new Set(user.groups?.map((g) => g.id) ?? [])
          const available = allGroups.filter((g) => !memberGroupIds.has(g.id))
          return available.length > 0 ? (
            <form onSubmit={handleAddGroup} style={{ display: 'flex', gap: 10, marginTop: 12, marginBottom: 12 }}>
              <select value={addGroupId} onChange={(e) => setAddGroupId(e.target.value)} required style={{ maxWidth: 280 }}>
                <option value="">— csoport kiválasztása —</option>
                {available.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
              </select>
              <button className="btn btn-primary btn-sm" type="submit">{t('group.add_member')}</button>
            </form>
          ) : null
        })()}

        {user.groups?.length === 0
          ? <p className="text-muted mt-4">Nincs csoporttagság.</p>
          : (
            <table style={{ marginTop: 8 }}>
              <thead><tr><th>{t('group.group_name')}</th><th>Leírás</th>{canManage && <th></th>}</tr></thead>
              <tbody>
                {user.groups.map((g) => (
                  <tr key={g.id}>
                    <td><Link to={`/groups/${g.id}`} className="table-link">{g.name}</Link></td>
                    <td className="text-muted">{g.description || '—'}</td>
                    {canManage && (
                      <td style={{ textAlign: 'right' }}>
                        <button className="btn btn-danger btn-sm" onClick={() => handleRemoveGroup(g.id)}>{t('group.remove_member')}</button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
      </div>

      {/* Jogosultságok */}
      <div className="card">
        <div style={{ marginBottom: 16 }}>
          <strong>{t('user.overrides')}</strong>
          <p className="text-muted" style={{ fontSize: 12, marginTop: 4 }}>
            A csoporttól kapott jog <span style={{ fontWeight: 700, color: '#1d4ed8' }}>kék</span> háttérrel jelölt.
            A felülírás felülbírálja a csoport döntését — engedélyezés (zöld) vagy tiltás (piros).
            „Nincs felülírás" esetén a csoport jogosultsága érvényes.
          </p>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(340px, 1fr))', gap: 24 }}>
          {Object.entries(byModule).map(([module, perms]) => (
            <div key={module}>
              <div style={{ fontWeight: 700, fontSize: 11, textTransform: 'uppercase', color: 'var(--color-muted)', marginBottom: 8, letterSpacing: '.05em' }}>
                {module}
              </div>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                <tbody>
                  {perms.map((p) => {
                    const fromGroup = fromGroupSet.has(p.id)
                    const effect    = overrides[p.id] ?? null
                    return (
                      <tr
                        key={p.id}
                        style={{
                          background: fromGroup ? '#eff6ff' : 'transparent',
                          borderBottom: '1px solid var(--color-border)',
                        }}
                      >
                        <td style={{ padding: '7px 8px', flex: 1 }}>
                          <span>{p.description}</span>
                          {p.is_sensitive && (
                            <span className="badge badge-inv-storno" style={{ fontSize: 10, marginLeft: 6 }}>érzékeny</span>
                          )}
                          {fromGroup && (
                            <span style={{ fontSize: 10, color: '#1d4ed8', marginLeft: 6 }}>● csoport</span>
                          )}
                        </td>
                        <td style={{ padding: '7px 8px', textAlign: 'right', whiteSpace: 'nowrap' }}>
                          <EffectToggle
                            effect={effect}
                            onChange={(v) => setOverride(p.id, v)}
                            disabled={!canOverride}
                          />
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
