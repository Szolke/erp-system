import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { companies as companiesApi } from '../../api/company'
import { users as usersApi } from '../../api/users'
import client from '../../api/client'
import ColumnPicker from '../../components/ColumnPicker'
import SortableColumnHeader from '../../components/SortableColumnHeader'
import { useListColumns } from '../../hooks/useListColumns'
import { companyColumns } from '../../columns/companies'

function renderCell(key, c, { t, activeCompanyId, switching, onShowUsers, onSwitch }) {
  switch (key) {
    case 'name':
      return (
        <td key="name">
          <strong>{c.name}</strong>
          {c.id === activeCompanyId && (
            <span className="badge badge-inv-issued" style={{ marginLeft: 8 }}>Aktív</span>
          )}
        </td>
      )
    case 'tax_number':
      return <td key="tax_number" className="text-muted">{c.tax_number}</td>
    case 'city':
      return <td key="city" className="text-muted">{c.city || '—'}</td>
    case 'users_count':
      return <td key="users_count">{c.users_count ?? 0}</td>
    case 'status':
      return (
        <td key="status">
          <span className={c.is_active ? 'badge badge-pay-paid' : 'badge badge-inv-storno'}>
            {c.is_active ? t('common.active') : 'Inaktív'}
          </span>
        </td>
      )
    case 'actions':
      return (
        <td key="actions" style={{ textAlign: 'right', whiteSpace: 'nowrap', display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
          <button
            className="btn btn-secondary btn-sm"
            onClick={() => onShowUsers(c)}
            style={{ fontSize: 11 }}
          >
            {t('company.show_users')} ({c.users_count ?? 0})
          </button>
          {c.id !== activeCompanyId && (
            <button
              className="btn btn-secondary btn-sm"
              onClick={() => onSwitch(c)}
              disabled={switching === c.id}
            >
              {switching === c.id ? '…' : 'Váltás'}
            </button>
          )}
        </td>
      )
    default:
      return null
  }
}

const EMPTY_FORM = { name: '', tax_number: '', city: '', address_line: '', postal_code: '', registration_number: '', email: '' }

export default function CompanyListPage() {
  const { user, activeCompanyId, switchCompany, refreshAuth } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()

  const [data, setData]         = useState(null)
  const [loading, setLoading]   = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm]         = useState(EMPTY_FORM)
  const [formErr, setFormErr]   = useState('')
  const [saving, setSaving]     = useState(false)
  const [switching, setSwitching] = useState(null)
  const [columnsOpen, setColumnsOpen] = useState(false)
  const { allColumns, visibleColumns, isVisible, toggle: toggleColumn, reorder, reset: resetColumns, isDirty, sort, toggleSort } =
    useListColumns('companies.index', companyColumns)

  // Users modal state
  const [usersModal, setUsersModal]         = useState(null)   // aktuálisan nyitott company obj
  const [modalUsers, setModalUsers]         = useState([])     // cég jelenlegi tagjai
  const [activeUsers, setActiveUsers]       = useState([])     // saját aktív cég userei (add-hoz)
  const [modalLoading, setModalLoading]     = useState(false)
  const [addUserId, setAddUserId]           = useState('')

  async function load() {
    setLoading(true)
    try {
      // Rendezés: a mentett preferenciából (nincs saját rendezés → a végpont a
      // saját alapértelmezését adja, l. CompanyController::SORTABLE_COLUMNS).
      const res = await companiesApi.list({ sort_by: sort?.by, sort_dir: sort?.dir })
      setData(res.data)
    } finally { setLoading(false) }
  }

  // Ez egyben a MOUNT-effekt is: az első lekérdezés már a mentett rendezéssel
  // indul. A lista nem lapoz, ezért itt nincs oldalszám-visszaállítás.
  useEffect(() => { load() }, [sort]) // eslint-disable-line react-hooks/exhaustive-deps

  function setField(e) {
    setForm((prev) => ({ ...prev, [e.target.name]: e.target.value }))
  }

  async function handleCreate(e) {
    e.preventDefault()
    setFormErr('')
    setSaving(true)
    try {
      await companiesApi.create(form)
      setForm(EMPTY_FORM)
      setShowForm(false)
      toast('Cég sikeresen létrehozva', 'success')
      await refreshAuth()
      load()
    } catch (err) {
      const errs = err.response?.data?.errors
      setFormErr(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? t('common.error'))
    } finally { setSaving(false) }
  }

  async function handleSwitch(company) {
    setSwitching(company.id)
    try {
      await switchCompany(company.id)
      navigate('/documents')
    } finally { setSwitching(null) }
  }

  async function openUsersModal(company) {
    setUsersModal(company)
    setAddUserId('')
    setModalLoading(true)
    try {
      // Cég tagjait az X-Company-Id fejléccel kérjük le (superadmin tagja minden saját cégnek)
      const [membersRes, myUsersRes] = await Promise.all([
        client.get('/api/users', { params: { per_page: 200 }, headers: { 'X-Company-Id': String(company.id) } }),
        usersApi.list({ per_page: 200 }),
      ])
      setModalUsers(membersRes.data.data ?? [])
      setActiveUsers(myUsersRes.data.data ?? [])
    } catch {
      setModalUsers([])
      setActiveUsers([])
    } finally { setModalLoading(false) }
  }

  function closeUsersModal() {
    setUsersModal(null)
    setModalUsers([])
    setActiveUsers([])
    setAddUserId('')
    // Frissíti az users_count-ot a listában
    load()
  }

  async function handleModalAttach(e) {
    e.preventDefault()
    if (!addUserId || !usersModal) return
    try {
      await usersApi.attachCompany(addUserId, usersModal.id)
      setAddUserId('')
      // Újratölti a modal users listát
      const res = await client.get('/api/users', { params: { per_page: 200 }, headers: { 'X-Company-Id': String(usersModal.id) } })
      setModalUsers(res.data.data ?? [])
      toast(t('company.user_added'), 'success')
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  async function handleModalDetach(targetUser) {
    if (!usersModal) return
    if (!window.confirm(`Biztosan eltávolítod „${targetUser.name}" felhasználót a(z) „${usersModal.name}" cégből?`)) return
    try {
      await usersApi.detachCompany(targetUser.id, usersModal.id)
      setModalUsers((prev) => prev.filter((u) => u.id !== targetUser.id))
      toast(t('company.user_removed'), 'success')
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  const list = data?.data ?? []

  if (!user?.is_superadmin) {
    return <p className="text-muted">{t('common.no_permission')}</p>
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Cégek</h1>
        <button className="btn btn-primary" onClick={() => setShowForm(!showForm)}>
          {showForm ? t('common.cancel') : '+ Új cég'}
        </button>
      </div>

      {showForm && (
        <div className="card" style={{ marginBottom: 20 }}>
          <strong>Új cég létrehozása</strong>
          <p className="text-muted mt-4" style={{ fontSize: 12 }}>
            A cég létrehozása után 4 alapértelmezett bizonylat-sorozat (SZ / NY / SZSZT / NYSZT) automatikusan létrejön.
            A többi adatot a Cégbeállítások oldalon lehet megadni.
          </p>
          {formErr && <div className="alert-error mt-4">{formErr}</div>}
          <form onSubmit={handleCreate} style={{ marginTop: 12 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 12 }}>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Cég neve *</label>
                <input name="name" value={form.name} onChange={setField} required placeholder="pl. Teszt Bt." />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Adószám * (12345678-1-42)</label>
                <input name="tax_number" value={form.tax_number} onChange={setField} required placeholder="12345678-1-42" />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Irányítószám</label>
                <input name="postal_code" value={form.postal_code} onChange={setField} placeholder="1000" />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Város</label>
                <input name="city" value={form.city} onChange={setField} placeholder="Budapest" />
              </div>
              <div className="form-group" style={{ margin: 0, gridColumn: '1 / -1' }}>
                <label>Cím</label>
                <input name="address_line" value={form.address_line} onChange={setField} placeholder="Példa utca 1." />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>Cégjegyzékszám</label>
                <input name="registration_number" value={form.registration_number} onChange={setField} placeholder="01-09-000001" />
              </div>
              <div className="form-group" style={{ margin: 0 }}>
                <label>E-mail</label>
                <input name="email" type="email" value={form.email} onChange={setField} />
              </div>
            </div>
            <div style={{ marginTop: 12, display: 'flex', gap: 8 }}>
              <button className="btn btn-primary" type="submit" disabled={saving}>
                {saving ? t('common.saving') : 'Létrehozás'}
              </button>
              <button className="btn btn-secondary" type="button" onClick={() => { setShowForm(false); setFormErr('') }}>
                {t('common.cancel')}
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="search-row">
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggleColumn} onReorder={reorder} onReset={resetColumns} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="companies-columns"
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
            {list.map((c) => (
              <tr key={c.id} style={c.id === activeCompanyId ? { background: 'var(--color-primary-subtle)' } : {}}>
                {visibleColumns.map((col) => renderCell(col.key, c, { t, activeCompanyId, switching, onShowUsers: openUsersModal, onSwitch: handleSwitch }))}
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && (
        <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total ?? list.length} {t('common.pieces')}</p>
      )}

      {/* Users modal */}
      {usersModal && (
        <div
          onClick={closeUsersModal}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.45)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
        >
          <div
            onClick={(e) => e.stopPropagation()}
            style={{
              background: 'var(--color-surface)', border: '1px solid var(--color-border)',
              borderRadius: 10, padding: 24, width: 560, maxWidth: '95vw', maxHeight: '80vh',
              overflowY: 'auto', boxShadow: '0 8px 32px rgba(0,0,0,0.18)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h2 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>{usersModal.name}</h2>
                <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--color-muted)' }}>{t('company.users_section')}</p>
              </div>
              <button
                onClick={closeUsersModal}
                style={{ background: 'none', border: 'none', fontSize: 20, cursor: 'pointer', color: 'var(--color-muted)', lineHeight: 1, padding: '0 4px' }}
              >×</button>
            </div>

            {modalLoading ? (
              <p className="text-muted">{t('common.loading')}</p>
            ) : (
              <>
                {/* Add user form */}
                {(() => {
                  const memberIds = new Set(modalUsers.map((u) => u.id))
                  const available = activeUsers.filter((u) => !memberIds.has(u.id))
                  return available.length > 0 ? (
                    <form onSubmit={handleModalAttach} style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
                      <select value={addUserId} onChange={(e) => setAddUserId(e.target.value)} required style={{ flex: 1 }}>
                        <option value="">— {t('company.add_user')} —</option>
                        {available.map((u) => (
                          <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
                        ))}
                      </select>
                      <button className="btn btn-primary btn-sm" type="submit">{t('common.add')}</button>
                    </form>
                  ) : null
                })()}

                {/* Current user list */}
                {modalUsers.length === 0 ? (
                  <p className="text-muted" style={{ fontSize: 13 }}>{t('company.no_users')}</p>
                ) : (
                  <table>
                    <thead>
                      <tr>
                        <th>{t('user.name')}</th>
                        <th>{t('user.email')}</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {modalUsers.map((u) => (
                        <tr key={u.id}>
                          <td>{u.name}</td>
                          <td className="text-muted">{u.email}</td>
                          <td style={{ textAlign: 'right' }}>
                            <button
                              className="btn btn-danger btn-sm"
                              onClick={() => handleModalDetach(u)}
                            >
                              {t('group.remove_member')}
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
