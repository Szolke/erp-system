import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { Eye, EyeOff, ChevronDown, ChevronRight, Check, Ban } from 'lucide-react'
import client from '../../api/client'
import { users as usersApi } from '../../api/users'
import { groups as groupsApi } from '../../api/groups'
import { companies as companiesApi } from '../../api/company'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import JobPositionSelect from '../../components/JobPositionSelect'
import BlameFooter from '../../components/BlameFooter'

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

function fmtDatetime(str) {
  if (!str) return null
  return new Date(str).toLocaleString('hu-HU', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
}

export default function UserDetailPage() {
  const { id } = useParams()
  const { user: authUser, can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const isSuperadmin = authUser?.is_superadmin
  const isOwnProfile = authUser?.id === Number(id)

  const [data, setData]           = useState(null)
  const [allPerms, setAllPerms]   = useState([])
  const [allGroups, setAllGroups] = useState([])
  const [overrides, setOverrides] = useState({})
  const [loading, setLoading]     = useState(true)
  const [saving, setSaving]       = useState(false)
  const [saved, setSaved]         = useState(false)
  const [error, setError]         = useState('')
  const [addGroupId, setAddGroupId] = useState('')
  const [activeTab, setActiveTab] = useState('profile')
  const [openModules, setOpenModules] = useState(() => new Set())
  const [permSearch, setPermSearch]   = useState('')

  // Cég-szekció state (csak superadminnak)
  const [userCompanies, setUserCompanies] = useState([])
  const [allCompanies,  setAllCompanies]  = useState([])
  const [addCompanyId,  setAddCompanyId]  = useState('')

  // Token-szekció state (superadmin vagy saját profil)
  const canViewTokens = isSuperadmin || isOwnProfile
  const [tokens, setTokens]           = useState([])
  const [tokensLoading, setTokensLoading] = useState(false)

  // Jelszócsere state (superadmin vagy saját profil)
  const [newPassword, setNewPassword]     = useState('')
  const [showPassword, setShowPassword]   = useState(false)
  const [pwSaving, setPwSaving]           = useState(false)

  // Munkakör state (user.manage jog kell a módosításhoz)
  const [jobPositionId, setJobPositionId] = useState(null)
  const [jpSaving, setJpSaving]           = useState(false)

  async function loadTokens() {
    if (!canViewTokens) return
    setTokensLoading(true)
    try {
      const res = await usersApi.listTokens(id)
      setTokens(res.data.data ?? [])
    } catch {
      setTokens([])
    } finally {
      setTokensLoading(false)
    }
  }

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

    // allSettled: ha valamelyik részleges hiba (pl. group.view hiánya), az oldal
    // akkor is betölt, csak az érintett szekció marad üres.
    const [uRes, pRes, gRes, ucRes, acRes] = await Promise.allSettled(promises)

    if (uRes.status === 'rejected') {
      setLoading(false)
      return
    }

    const d = uRes.value.data
    setData(d)
    setJobPositionId(d.user.job_position_id ?? null)
    setAllPerms(pRes.status === 'fulfilled' ? (pRes.value.data.data ?? []) : [])
    setAllGroups(gRes.status === 'fulfilled' ? (gRes.value.data.data ?? []) : [])

    const map = {}
    Object.entries(d.overrides ?? {}).forEach(([k, v]) => { map[Number(k)] = v })
    setOverrides(map)

    if (isSuperadmin) {
      setUserCompanies(ucRes?.status === 'fulfilled' ? (ucRes.value?.data?.data ?? []) : [])
      setAllCompanies(acRes?.status === 'fulfilled' ? (acRes.value?.data?.data ?? []) : [])
    }

    setLoading(false)
  }

  useEffect(() => {
    load()
    loadTokens()
  }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  // Első betöltéskor minden modul-szekció nyitva induljon
  useEffect(() => {
    if (allPerms.length > 0 && openModules.size === 0) {
      setOpenModules(new Set(allPerms.map((p) => p.module)))
    }
  }, [allPerms]) // eslint-disable-line react-hooks/exhaustive-deps

  function toggleModule(module) {
    setOpenModules((prev) => {
      const next = new Set(prev)
      if (next.has(module)) next.delete(module)
      else next.add(module)
      return next
    })
  }

  function setOverride(permId, effect) {
    setOverrides((prev) => ({ ...prev, [permId]: effect }))
    setSaved(false)
  }

  function setOverridesFor(perms, effect) {
    setOverrides((prev) => {
      const next = { ...prev }
      perms.forEach((p) => { next[p.id] = effect })
      return next
    })
    setSaved(false)
  }

  async function handleSave() {
    setSaving(true)
    setError('')
    setSaved(false)
    try {
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

  async function handleRevokeToken(tokenId, deviceName) {
    if (!window.confirm(t('user.token_revoke_confirm'))) return
    try {
      await usersApi.deleteToken(id, tokenId)
      toast(t('user.token_revoked'), 'success')
      loadTokens()
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  async function handleSaveJobPosition() {
    setJpSaving(true)
    try {
      const res = await usersApi.update(id, { job_position_id: jobPositionId })
      setData((prev) => ({
        ...prev,
        user: { ...prev.user, job_position_id: res.data.job_position_id, job_position: res.data.job_position },
      }))
      toast(t('common.saved'), 'success')
    } catch (err) {
      const msg = err.response?.data?.errors?.job_position_id?.[0]
        ?? err.response?.data?.message
        ?? t('common.error')
      toast(msg, 'error')
    } finally {
      setJpSaving(false)
    }
  }

  async function handleChangePassword(e) {
    e.preventDefault()
    if (!newPassword) return
    setPwSaving(true)
    try {
      await usersApi.changePassword(id, newPassword)
      setNewPassword('')
      setShowPassword(false)
      toast(t('user.password_changed'), 'success')
    } catch (err) {
      const msg = err.response?.data?.errors?.password?.[0]
        ?? err.response?.data?.message
        ?? t('common.error')
      toast(msg, 'error')
    } finally {
      setPwSaving(false)
    }
  }

  if (loading) return <p className="text-muted">{t('common.loading')}</p>
  if (!data)   return <p className="text-muted">{t('common.not_found')}</p>

  const { user, from_groups: fromGroups } = data
  const fromGroupSet = new Set(fromGroups ?? [])
  const canManage       = can('group.manage')
  const canOverride     = can('permission.override')
  const canManageUsers  = can('user.manage')

  const assignedCompanyIds = new Set(userCompanies.map((c) => c.id))
  const availableCompanies = allCompanies.filter((c) => !assignedCompanyIds.has(c.id))

  const byModule = allPerms.reduce((acc, p) => {
    ;(acc[p.module] ??= []).push(p)
    return acc
  }, {})

  // Kereséskor minden modul kinyílik, és csak az egyező sorok jelennek meg
  const permSearchLower = permSearch.trim().toLowerCase()
  const filteredByModule = Object.fromEntries(
    Object.entries(byModule)
      .map(([module, perms]) => [
        module,
        permSearchLower
          ? perms.filter((p) => p.description.toLowerCase().includes(permSearchLower) || p.key.toLowerCase().includes(permSearchLower))
          : perms,
      ])
      .filter(([, perms]) => perms.length > 0),
  )

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">{user.name}</h1>
          <p className="text-muted">{user.email}</p>
        </div>
        <div className="flex">
          {canOverride && activeTab === 'permissions' && (
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

      {/* Fülek */}
      <div style={{ display: 'flex', gap: 4, marginBottom: 16 }}>
        <button
          className={`btn ${activeTab === 'profile' ? 'btn-primary' : 'btn-secondary'}`}
          onClick={() => setActiveTab('profile')}
        >
          {t('user.tab_profile')}
        </button>
        <button
          className={`btn ${activeTab === 'permissions' ? 'btn-primary' : 'btn-secondary'}`}
          onClick={() => setActiveTab('permissions')}
        >
          {t('user.tab_permissions')}
        </button>
      </div>

      {activeTab === 'profile' && (
        <>
      {/* Munkakör — user.manage jog kell a módosításhoz */}
      {canManageUsers && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong>Munkakör</strong>
          <div style={{ marginTop: 12, display: 'flex', gap: 8, alignItems: 'flex-end' }}>
            <div className="form-group" style={{ margin: 0, maxWidth: 280 }}>
              <JobPositionSelect
                value={jobPositionId}
                onChange={setJobPositionId}
                currentJobPosition={user.job_position}
              />
            </div>
            <button
              className="btn btn-primary btn-sm"
              type="button"
              onClick={handleSaveJobPosition}
              disabled={jpSaving}
            >
              {jpSaving ? t('common.saving') : t('common.save')}
            </button>
          </div>
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

      {/* Tokenek / eszközök — superadmin vagy saját profil */}
      {canViewTokens && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong>{t('user.tokens_section')}</strong>

          {tokensLoading ? (
            <p className="text-muted" style={{ marginTop: 12, fontSize: 13 }}>{t('common.loading')}</p>
          ) : tokens.length === 0 ? (
            <p className="text-muted" style={{ marginTop: 12, fontSize: 13 }}>{t('user.no_tokens')}</p>
          ) : (
            <table style={{ marginTop: 12 }}>
              <thead>
                <tr>
                  <th>{t('user.token_device')}</th>
                  <th>{t('user.token_created')}</th>
                  <th>{t('user.token_last_used')}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {tokens.map((tok) => (
                  <tr key={tok.id}>
                    <td><strong>{tok.name}</strong></td>
                    <td className="text-muted" style={{ whiteSpace: 'nowrap', fontSize: 13 }}>
                      {fmtDatetime(tok.created_at)}
                    </td>
                    <td className="text-muted" style={{ whiteSpace: 'nowrap', fontSize: 13 }}>
                      {tok.last_used_at
                        ? fmtDatetime(tok.last_used_at)
                        : <em>{t('user.token_never_used')}</em>}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <button
                        className="btn btn-danger btn-sm"
                        onClick={() => handleRevokeToken(tok.id, tok.name)}
                      >
                        {t('user.token_revoke')}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {/* Jelszó módosítása — superadmin vagy saját profil */}
      {canViewTokens && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong>{t('user.password_section')}</strong>
          <form onSubmit={handleChangePassword} style={{ marginTop: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
            <div style={{ position: 'relative' }}>
              <input
                type={showPassword ? 'text' : 'password'}
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder={t('user.new_password')}
                style={{ paddingRight: 36, minWidth: 220 }}
                autoComplete="new-password"
              />
              <button
                type="button"
                onClick={() => setShowPassword((v) => !v)}
                title={showPassword ? t('user.password_hide') : t('user.password_show')}
                style={{
                  position: 'absolute', right: 8, top: '50%', transform: 'translateY(-50%)',
                  background: 'none', border: 'none', cursor: 'pointer',
                  color: 'var(--color-muted)', padding: 0, display: 'flex',
                }}
              >
                {showPassword ? <EyeOff size={15} /> : <Eye size={15} />}
              </button>
            </div>
            <button
              className="btn btn-primary btn-sm"
              type="submit"
              disabled={pwSaving || newPassword.length < 8}
            >
              {pwSaving ? t('common.saving') : t('user.password_save')}
            </button>
          </form>
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
        </>
      )}

      {/* Jogosultságok */}
      {activeTab === 'permissions' && (
        <div className="card">
          <div style={{ marginBottom: 16 }}>
            <strong>{t('user.overrides')}</strong>
            <p className="text-muted" style={{ fontSize: 12, marginTop: 4 }}>
              A csoporttól kapott jog <span style={{ fontWeight: 700, color: '#1d4ed8' }}>kék</span> háttérrel jelölt.
              A felülírás felülbírálja a csoport döntését — engedélyezés (zöld) vagy tiltás (piros).
              „Nincs felülírás" esetén a csoport jogosultsága érvényes.
            </p>
          </div>

          <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 16, flexWrap: 'wrap' }}>
            <input
              type="text"
              value={permSearch}
              onChange={(e) => setPermSearch(e.target.value)}
              placeholder={t('user.permission_search')}
              style={{ maxWidth: 320 }}
            />
            {canOverride && (
              <div style={{ display: 'flex', gap: 6, marginLeft: 'auto' }}>
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setOverridesFor(allPerms, 'allow')}>
                  {t('user.overrides_allow_all')}
                </button>
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setOverridesFor(allPerms, 'deny')}>
                  {t('user.overrides_deny_all')}
                </button>
              </div>
            )}
          </div>

          {Object.entries(filteredByModule).map(([module, perms]) => {
            const isOpen = !!permSearchLower || openModules.has(module)
            return (
              <div key={module} style={{ borderTop: '1px solid var(--color-border)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 4px' }}>
                  <button
                    type="button"
                    onClick={() => toggleModule(module)}
                    style={{
                      display: 'flex', alignItems: 'center', gap: 8, flex: 1, minWidth: 0,
                      background: 'none', border: 'none', cursor: 'pointer',
                      textAlign: 'left', fontWeight: 700, fontSize: 11, textTransform: 'uppercase',
                      color: 'var(--color-muted)', letterSpacing: '.05em',
                    }}
                  >
                    {isOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                    {module}
                  </button>
                  {canOverride && (
                    <div style={{ display: 'flex', gap: 2 }}>
                      <button
                        type="button"
                        onClick={() => setOverridesFor(byModule[module] ?? [], 'allow')}
                        title={t('user.overrides_module_allow')}
                        style={{
                          background: 'none', border: 'none', cursor: 'pointer', padding: 4,
                          display: 'flex', color: 'var(--color-success-text)',
                        }}
                      >
                        <Check size={14} />
                      </button>
                      <button
                        type="button"
                        onClick={() => setOverridesFor(byModule[module] ?? [], 'deny')}
                        title={t('user.overrides_module_deny')}
                        style={{
                          background: 'none', border: 'none', cursor: 'pointer', padding: 4,
                          display: 'flex', color: 'var(--color-danger)',
                        }}
                      >
                        <Ban size={14} />
                      </button>
                    </div>
                  )}
                  <span style={{ fontWeight: 400, fontSize: 11, color: 'var(--color-muted)' }}>
                    {perms.length}
                  </span>
                </div>
                {isOpen && (
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, marginBottom: 8 }}>
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
                            <td style={{ padding: '7px 8px' }}>
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
                )}
              </div>
            )
          })}
        </div>
      )}

      {/* Fülfüggetlen: a blame magára a felhasználó-rekordra vonatkozik, nem az
          épp aktív fül tartalmára. A `data.user` nyers modell-JSON. */}
      <BlameFooter createdBy={user.created_by} updatedBy={user.updated_by} />
    </div>
  )
}
