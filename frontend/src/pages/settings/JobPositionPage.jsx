import { useEffect, useState } from 'react'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { jobPositions as jpApi } from '../../api/jobPositions'
import ColumnPicker from '../../components/ColumnPicker'
import { useListColumns } from '../../hooks/useListColumns'
import { jobPositionColumns } from '../../columns/jobPositions'

function renderCell(key, jp, { t, editId, canEditRow, onEditStart, onDelete }) {
  switch (key) {
    case 'name':
      return <td key="name">{jp.name}</td>
    case 'scope':
      return <td key="scope" className="text-muted" style={{ fontSize: 13 }}>{jp.is_global ? 'Globális' : 'Saját cég'}</td>
    case 'status':
      return (
        <td key="status">
          <span className={jp.active ? 'badge badge-pay-paid' : 'badge badge-inv-storno'}>
            {jp.active ? t('common.active') : 'Inaktív'}
          </span>
        </td>
      )
    case 'sort_order':
      return <td key="sort_order" className="text-muted">{jp.sort_order}</td>
    case 'actions':
      return (
        <td key="actions" style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
          {editId !== jp.id && canEditRow(jp) && (
            <>
              <button
                className="btn btn-secondary btn-sm"
                style={{ marginRight: 6 }}
                onClick={() => onEditStart(jp)}
              >
                {t('common.edit')}
              </button>
              <button className="btn btn-danger btn-sm" onClick={() => onDelete(jp)}>
                {t('common.delete')}
              </button>
            </>
          )}
        </td>
      )
    default:
      return null
  }
}

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
      <div className="form-group" style={{ margin: 0 }}>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, textTransform: 'none', fontWeight: 400, letterSpacing: 'normal', fontSize: 13, color: 'var(--color-text)' }}>
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} style={{ width: 'auto' }} />
          Aktív
        </label>
        <p className="text-muted" style={{ fontSize: 12, margin: '4px 0 0' }}>
          Inaktív munkakör nem választható újonnan a felhasználó-űrlapon.
        </p>
      </div>
      {!initial && allowGlobal && (
        <div className="form-group" style={{ margin: 0 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, textTransform: 'none', fontWeight: 400, letterSpacing: 'normal', fontSize: 13, color: 'var(--color-text)' }}>
            <input type="checkbox" checked={global} onChange={(e) => setGlobal(e.target.checked)} style={{ width: 'auto' }} />
            Globális
          </label>
          <p className="text-muted" style={{ fontSize: 12, margin: '4px 0 0' }}>
            Minden céget érint, nem csak a jelenlegit.
          </p>
        </div>
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
  const [columnsOpen, setColumnsOpen] = useState(false)
  const { allColumns, visibleColumns, isVisible, toggle: toggleColumn, reorder, reset: resetColumns, isDirty } = useListColumns('job_positions.index', jobPositionColumns)

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

      <div className="search-row">
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggleColumn} onReorder={reorder} onReset={resetColumns} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="job-positions-columns"
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
              <tr><td colSpan={visibleColumns.length} className="text-muted">Még nincs munkakör.</td></tr>
            )}
            {list.map((jp) => (
              <tr key={jp.id}>
                {visibleColumns.map((col) => renderCell(col.key, jp, {
                  t, editId, canEditRow,
                  onEditStart: (position) => { setEditId(position.id); setShowCreate(false) },
                  onDelete: handleDelete,
                }))}
              </tr>
            ))}
            {editId !== null && (
              <tr>
                <td colSpan={visibleColumns.length}>
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
