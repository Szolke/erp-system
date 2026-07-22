import { useEffect, useState } from 'react'
import { customFields as api } from '../../api/customFields'
import { useTranslation } from '../../contexts/TranslationContext'
import ColumnPicker from '../../components/ColumnPicker'
import { useListColumns } from '../../hooks/useListColumns'
import { customFieldColumns } from '../../columns/customFields'

const ENTITY_TYPES = ['partner', 'product']
const FIELD_TYPES  = ['text', 'number', 'date', 'boolean', 'select']

function renderCell(key, def, { t, onEdit, onDelete }) {
  switch (key) {
    case 'key':
      return <td key="key" style={{ fontFamily: 'monospace' }}>{def.key}</td>
    case 'label':
      return <td key="label">{def.label}</td>
    case 'type':
      return <td key="type">{t(`customfield.type_${def.type}`)}</td>
    case 'required':
      return <td key="required">{def.is_required ? t('common.yes') : t('common.no')}</td>
    case 'active':
      return <td key="active">{def.is_active ? '✓' : '—'}</td>
    case 'actions':
      return (
        <td key="actions">
          <span style={{ display: 'flex', gap: 6 }}>
            <button className="btn btn-secondary btn-sm" onClick={() => onEdit(def)}>{t('common.edit')}</button>
            <button className="btn btn-danger btn-sm" onClick={() => onDelete(def)}>{t('common.delete')}</button>
          </span>
        </td>
      )
    default:
      return null
  }
}

function emptyForm(entityType = 'partner') {
  return {
    entity_type: entityType,
    key: '',
    label: '',
    type: 'text',
    options: '',
    is_required: false,
    sort_order: 0,
    is_active: true,
  }
}

export default function CustomFieldsPage() {
  const { t } = useTranslation()
  const [defs, setDefs]         = useState([])
  const [loading, setLoading]   = useState(true)
  const [editing, setEditing]   = useState(null) // null | { ...form, id? }
  const [saving, setSaving]     = useState(false)
  const [error, setError]       = useState('')
  const [activeTab, setActiveTab] = useState('partner')
  const [columnsOpen, setColumnsOpen] = useState(false)
  const { allColumns, visibleColumns, isVisible, toggle: toggleColumn, reorder, reset: resetColumns, isDirty } = useListColumns('custom_fields.index', customFieldColumns)

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const res = await api.list()
      setDefs(res.data.data)
    } finally { setLoading(false) }
  }

  function startAdd() {
    setEditing(emptyForm(activeTab))
    setError('')
  }

  function startEdit(def) {
    setEditing({
      ...def,
      options: Array.isArray(def.options) ? def.options.join('\n') : '',
    })
    setError('')
  }

  async function handleSave(e) {
    e.preventDefault()
    setSaving(true)
    setError('')
    try {
      const payload = {
        entity_type: editing.entity_type,
        key: editing.key,
        label: editing.label,
        type: editing.type,
        options: editing.type === 'select'
          ? editing.options.split('\n').map((s) => s.trim()).filter(Boolean)
          : null,
        is_required: editing.is_required,
        sort_order: Number(editing.sort_order),
        is_active: editing.is_active,
      }

      if (editing.id) {
        await api.update(editing.id, payload)
      } else {
        await api.create(payload)
      }

      setEditing(null)
      await load()
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? t('common.error'))
    } finally { setSaving(false) }
  }

  async function handleDelete(def) {
    if (!window.confirm(t('customfield.del_confirm'))) return
    await api.delete(def.id)
    await load()
  }

  function setField(k, v) { setEditing((f) => ({ ...f, [k]: v })) }

  const tabDefs = defs.filter((d) => d.entity_type === activeTab)

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('customfield.title')}</h1>
      </div>

      {/* Fülek */}
      <div style={{ display: 'flex', gap: 4, marginBottom: 16 }}>
        {ENTITY_TYPES.map((et) => (
          <button
            key={et}
            className={`btn ${activeTab === et ? 'btn-primary' : 'btn-secondary'}`}
            onClick={() => { setActiveTab(et); setEditing(null) }}
          >
            {t(`customfield.entity_${et}`)}
          </button>
        ))}
      </div>

      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <strong>{t(`customfield.entity_${activeTab}`)}</strong>
          <div style={{ display: 'flex', gap: 8 }}>
            <ColumnPicker
              columns={allColumns} isVisible={isVisible} onToggle={toggleColumn} onReorder={reorder} onReset={resetColumns} isDirty={isDirty}
              open={columnsOpen} onOpenChange={setColumnsOpen} id="custom-fields-columns"
              align="right"
            />
            {!editing && (
              <button className="btn btn-secondary btn-sm" onClick={startAdd}>{t('customfield.new')}</button>
            )}
          </div>
        </div>

        {loading ? (
          <p className="text-muted">{t('common.loading')}</p>
        ) : (
          <>
            {tabDefs.length > 0 && (
              <table style={{ marginBottom: 16 }}>
                <thead>
                  <tr>
                    {visibleColumns.map((col) => (
                      <th key={col.key}>{t(col.label)}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {tabDefs.map((def) => (
                    <tr key={def.id}>
                      {visibleColumns.map((col) => renderCell(col.key, def, { t, onEdit: startEdit, onDelete: handleDelete }))}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}

            {tabDefs.length === 0 && !editing && (
              <p className="text-muted">Még nincs egyéni mező ehhez az entitáshoz.</p>
            )}

            {editing && (
              <div className="card" style={{ background: 'var(--color-surface-alt)', marginTop: 8 }}>
                {error && <div className="alert-error mb-4">{error}</div>}
                <form onSubmit={handleSave}>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>

                    {!editing.id && (
                      <div className="form-group">
                        <label>{t('customfield.key')} <small className="text-muted">({t('customfield.key_hint')})</small></label>
                        <input
                          value={editing.key}
                          onChange={(e) => setField('key', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''))}
                          placeholder="pl. belso_azonosito"
                          required
                        />
                      </div>
                    )}

                    <div className="form-group">
                      <label>{t('customfield.label')}</label>
                      <input value={editing.label} onChange={(e) => setField('label', e.target.value)} required />
                    </div>

                    <div className="form-group">
                      <label>{t('customfield.type')}</label>
                      <select value={editing.type} onChange={(e) => setField('type', e.target.value)} disabled={!!editing.id}>
                        {FIELD_TYPES.map((ft) => (
                          <option key={ft} value={ft}>{t(`customfield.type_${ft}`)}</option>
                        ))}
                      </select>
                    </div>

                    <div className="form-group">
                      <label>Sorrend</label>
                      <input type="number" min={0} value={editing.sort_order} onChange={(e) => setField('sort_order', e.target.value)} />
                    </div>

                    <div className="form-group" style={{ alignSelf: 'end', display: 'flex', gap: 16 }}>
                      <label style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
                        <input type="checkbox" checked={editing.is_required} onChange={(e) => setField('is_required', e.target.checked)} />
                        {t('customfield.required')}
                      </label>
                      <label style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
                        <input type="checkbox" checked={editing.is_active} onChange={(e) => setField('is_active', e.target.checked)} />
                        {t('common.active')}
                      </label>
                    </div>

                    {editing.type === 'select' && (
                      <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                        <label>{t('customfield.options')}</label>
                        <textarea
                          rows={4}
                          value={editing.options}
                          onChange={(e) => setField('options', e.target.value)}
                          placeholder={'Aktív\nPasszív\nFüggőben'}
                          required
                        />
                      </div>
                    )}
                  </div>

                  <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                    <button className="btn btn-primary" type="submit" disabled={saving}>
                      {saving ? t('common.saving') : t('common.save')}
                    </button>
                    <button className="btn btn-secondary" type="button" onClick={() => setEditing(null)}>
                      {t('common.cancel')}
                    </button>
                  </div>
                </form>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
