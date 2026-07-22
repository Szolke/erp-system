import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { salesGroups as sgApi } from '../../api/salesGroups'
import { company as companyApi } from '../../api/company'
import Pagination from '../../components/Pagination'
import PerPageSelector from '../../components/PerPageSelector'
import ColumnPicker from '../../components/ColumnPicker'
import { useListColumns } from '../../hooks/useListColumns'
import { salesGroupColumns } from '../../columns/salesGroups'

function renderCell(key, g, { t, can, editId, onEditStart, onDelete }) {
  switch (key) {
    case 'display_name':
      return <td key="display_name"><code style={{ fontSize: 13 }}>{g.display_name}</code></td>
    case 'name':
      return <td key="name" className="text-muted" style={{ fontSize: 13 }}>{g.name}</td>
    case 'actions':
      return (
        <td key="actions" style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
          {editId !== g.id ? (
            <>
              {can('sales_group.edit') && (
                <button
                  className="btn btn-secondary btn-sm"
                  style={{ marginRight: 6 }}
                  onClick={() => onEditStart(g)}
                >
                  {t('common.edit')}
                </button>
              )}
              {can('sales_group.delete') && (
                <button
                  className="btn btn-danger btn-sm"
                  onClick={() => onDelete(g)}
                >
                  {t('common.delete')}
                </button>
              )}
            </>
          ) : null}
        </td>
      )
    default:
      return null
  }
}

function displayName(prefix, name) {
  return prefix ? `${prefix}_${name}` : name
}

function GroupForm({ prefix, initial, onSave, onCancel, saving }) {
  const [name, setName] = useState(initial?.name ?? '')
  const [err, setErr]   = useState('')

  async function handleSubmit(e) {
    e.preventDefault()
    setErr('')
    try {
      await onSave(name)
    } catch (error) {
      const errs = error.response?.data?.errors
      setErr(errs ? Object.values(errs).flat().join(' | ') : error.response?.data?.message ?? 'Hiba')
    }
  }

  const preview = name.trim() ? displayName(prefix, name.trim()) : ''

  return (
    <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      {err && <div className="alert-error">{err}</div>}
      <div className="form-group" style={{ margin: 0 }}>
        <label>Csoport neve</label>
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          maxLength={100}
          placeholder="pl. Észak"
          autoFocus
        />
      </div>
      {preview && (
        <div style={{ fontSize: 12, color: 'var(--color-muted)' }}>
          Megjelenítőnév:{' '}
          <code style={{ fontSize: 12, color: 'var(--color-text)' }}>{preview}</code>
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

export default function SalesGroupPage() {
  const { can } = useAuth()
  const { t }   = useTranslation()
  const toast   = useToast()

  const [data, setData]         = useState(null)
  const [loading, setLoading]   = useState(true)
  const [prefix, setPrefix]     = useState(null)
  const [perPage, setPerPage]   = useState(20)
  const [page, setPage]         = useState(1)

  const [showCreate, setShowCreate] = useState(false)
  const [editId, setEditId]         = useState(null)
  const [saving, setSaving]         = useState(false)
  const [columnsOpen, setColumnsOpen] = useState(false)
  const { allColumns, visibleColumns, isVisible, toggle: toggleColumn, reset: resetColumns, isDirty } = useListColumns('sales_groups.index', salesGroupColumns)

  async function load(pp = perPage, pg = page) {
    setLoading(true)
    try {
      const [sgRes, compRes] = await Promise.all([
        sgApi.list({ per_page: pp, page: pg }),
        companyApi.get(),
      ])
      setData(sgRes.data)
      setPrefix(compRes.data.data?.group_prefix ?? null)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  function handlePerPage(value) {
    setPerPage(value)
    setPage(1)
    load(value, 1)
  }

  async function handleCreate(name) {
    setSaving(true)
    try {
      await sgApi.create({ name })
      setShowCreate(false)
      toast('Csoport létrehozva.', 'success')
      load()
    } finally {
      setSaving(false)
    }
  }

  async function handleUpdate(id, name) {
    setSaving(true)
    try {
      await sgApi.update(id, { name })
      setEditId(null)
      toast('Csoport módosítva.', 'success')
      load()
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(group) {
    if (!confirm(`Törli a(z) „${group.display_name}" csoportot?`)) return
    try {
      await sgApi.remove(group.id)
      toast('Csoport törölve.', 'success')
      load()
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  const list = data?.data ?? []

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Értékesítő csoportok</h1>
        {can('sales_group.create') && !showCreate && (
          <button className="btn btn-primary" onClick={() => { setShowCreate(true); setEditId(null) }}>
            + Új csoport
          </button>
        )}
      </div>

      {/* Prefix-hiány figyelmeztetés */}
      {!loading && prefix === null && (
        <div className="alert-error mb-4" style={{ fontWeight: 500 }}>
          Előbb állíts be egy <strong>értékesítő csoport prefixet</strong> a{' '}
          <Link
            to="/company#section-sales-group-prefix"
            style={{ color: 'inherit', textDecoration: 'underline' }}
          >
            Cégbeállításoknál
          </Link>
          . Prefix nélkül nem hozható létre csoport.
        </div>
      )}

      {/* Létrehozó form */}
      {showCreate && can('sales_group.create') && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong style={{ display: 'block', marginBottom: 12 }}>Új csoport</strong>
          <GroupForm
            prefix={prefix}
            onSave={handleCreate}
            onCancel={() => setShowCreate(false)}
            saving={saving}
          />
        </div>
      )}

      <div className="search-row">
        <PerPageSelector value={perPage} onChange={handlePerPage} />
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggleColumn} onReset={resetColumns} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="sales-groups-columns"
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
              <tr>
                <td colSpan={visibleColumns.length} className="text-muted">
                  {prefix ? 'Még nincs értékesítő csoport.' : 'Prefix beállítása után hozható létre csoport.'}
                </td>
              </tr>
            )}
            {list.map((g) => (
              <tr key={g.id}>
                {visibleColumns.map((col) => renderCell(col.key, g, {
                  t, can, editId,
                  onEditStart: (group) => { setEditId(group.id); setShowCreate(false) },
                  onDelete: handleDelete,
                }))}
              </tr>
            ))}
            {/* Szerkesztő sor — a szerkesztett elem alatt */}
            {editId !== null && (
              <tr>
                <td colSpan={visibleColumns.length}>
                  <div style={{ padding: '8px 0' }}>
                    <GroupForm
                      prefix={prefix}
                      initial={list.find((g) => g.id === editId)}
                      onSave={(name) => handleUpdate(editId, name)}
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

      {data && (
        <p className="text-muted mt-4">
          {t('common.total')}: {data.meta?.total} {t('common.pieces')}
        </p>
      )}
      <Pagination
        meta={data?.meta}
        onChange={(p) => { setPage(p); load(perPage, p) }}
      />
    </div>
  )
}
