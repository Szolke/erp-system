import { useEffect, useState } from 'react'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { jobPositions as jpApi } from '../../api/jobPositions'

function JobPositionForm({ initial, allowGlobal, onSave, onCancel, saving }) {
  const [name, setName]         = useState(initial?.name ?? '')
  const [active, setActive]     = useState(initial?.active ?? true)
  const [sortOrder, setSortOrder] = useState(initial?.sort_order ?? 0)
  const [global, setGlobal]     = useState(false)
  const [err, setErr]           = useState('')

  async function handleSubmit(e) {
    e.preventDefault()
    setErr('')
    try {
      await onSave({ name, active, sort_order: Number(sortOrder) || 0, ...(initial ? {} : { global }) })
    } catch (error) {
      const errs = error.response?.data?.errors
      setErr(errs ? Object.values(errs).flat().join(' | ') : error.response?.data?.message ?? 'Hiba')
    }
  }

  return (
    <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      {err && <div className="alert-error">{err}</div>}
      <div className="form-group" style={{ margin: 0 }}>
        <label>Munkakör neve</label>
        <input value={name} onChange={(e) => setName(e.target.value)} required maxLength={100} placeholder="pl. Recepciós" autoFocus />
      </div>
      <div className="form-group" style={{ margin: 0 }}>
        <label>Sorrend</label>
        <input type="number" min={0} value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} style={{ maxWidth: 120 }} />
      </div>
      <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
        Aktív (inaktív munkakör nem választható újonnan a felhasználó-űrlapon)
      </label>
      {!initial && allowGlobal && (
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
          <input type="checkbox" checked={global} onChange={(e) => setGlobal(e.target.checked)} />
          Globális (minden céget érint, nem csak a jelenlegit)
        </label>
      )}
      <div style={{ display: 'flex', gap: 8 }}>
        <button className="btn btn-primary btn-sm" type="submit" disabled={saving || !name.trim()}>
          {saving ? 'Mentés...' : initial ? 'Mentés' : 'Létrehozás'}
        </button>
        <button className="btn btn-secondary btn-sm" type="button" onClick={onCancel}>
          Mégsem
        </button>
      </div>
    </form>
  )
}

export default function JobPositionPage() {
  const { user, can } = useAuth()
  const { t }          = useTranslation()
  const toast          = useToast()
  const isSuperadmin    = !!user?.is_superadmin
  const canManage       = can('job_position.manage')

  const [list, setList]             = useState([])
  const [loading, setLoading]       = useState(true)
  const [showCreate, setShowCreate] = useState(false)
  const [editId, setEditId]         = useState(null)
  const [saving, setSaving]         = useState(false)

  async function load() {
    setLoading(true)
    try {
      const res = await jpApi.list(canManage ? { all: 1 } : undefined)
      setList(res.data.data ?? [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function handleCreate(payload) {
    setSaving(true)
    try {
      await jpApi.create(payload)
      setShowCreate(false)
      toast('Munkakör létrehozva.', 'success')
      load()
    } finally {
      setSaving(false)
    }
  }

  async function handleUpdate(id, payload) {
    setSaving(true)
    try {
      await jpApi.update(id, payload)
      setEditId(null)
      toast('Munkakör módosítva.', 'success')
      load()
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(jp) {
    if (!confirm(`Törli a(z) „${jp.name}" munkakört?`)) return
    try {
      await jpApi.remove(jp.id)
      toast('Munkakör törölve.', 'success')
      load()
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  function canEditRow(jp) {
    return canManage && (!jp.is_global || isSuperadmin)
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Munkakörök</h1>
        {canManage && !showCreate && (
          <button className="btn btn-primary" onClick={() => { setShowCreate(true); setEditId(null) }}>
            + Új munkakör
          </button>
        )}
      </div>

      {showCreate && canManage && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong style={{ display: 'block', marginBottom: 12 }}>Új munkakör</strong>
          <JobPositionForm
            allowGlobal={isSuperadmin}
            onSave={handleCreate}
            onCancel={() => setShowCreate(false)}
            saving={saving}
          />
        </div>
      )}

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Név</th>
              <th>Kör</th>
              <th>Állapot</th>
              <th>Sorrend</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {list.length === 0 && (
              <tr><td colSpan={5} className="text-muted">Még nincs munkakör.</td></tr>
            )}
            {list.map((jp) => (
              <tr key={jp.id}>
                <td>{jp.name}</td>
                <td className="text-muted" style={{ fontSize: 13 }}>
                  {jp.is_global ? 'Globális' : 'Saját cég'}
                </td>
                <td>
                  <span className={jp.active ? 'badge badge-pay-paid' : 'badge badge-inv-storno'}>
                    {jp.active ? t('common.active') : 'Inaktív'}
                  </span>
                </td>
                <td className="text-muted">{jp.sort_order}</td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  {editId !== jp.id && canEditRow(jp) && (
                    <>
                      <button
                        className="btn btn-secondary btn-sm"
                        style={{ marginRight: 6 }}
                        onClick={() => { setEditId(jp.id); setShowCreate(false) }}
                      >
                        {t('common.edit')}
                      </button>
                      <button className="btn btn-danger btn-sm" onClick={() => handleDelete(jp)}>
                        {t('common.delete')}
                      </button>
                    </>
                  )}
                </td>
              </tr>
            ))}
            {editId !== null && (
              <tr>
                <td colSpan={5}>
                  <div style={{ padding: '8px 0' }}>
                    <JobPositionForm
                      initial={list.find((jp) => jp.id === editId)}
                      allowGlobal={isSuperadmin}
                      onSave={(payload) => handleUpdate(editId, payload)}
                      onCancel={() => setEditId(null)}
                      saving={saving}
                    />
                  </div>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      )}
    </div>
  )
}
