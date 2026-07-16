import { useEffect, useState } from 'react'
import { assetTypes } from '../../api/assets'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'

function TypeForm({ onSave, onCancel, saving }) {
  const { t } = useTranslation()
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [err, setErr]   = useState('')

  async function handleSubmit(e) {
    e.preventDefault()
    setErr('')
    try {
      await onSave(code, name)
    } catch (error) {
      const errs = error.response?.data?.errors
      setErr(errs ? Object.values(errs).flat().join(' | ') : error.response?.data?.message ?? 'Hiba')
    }
  }

  return (
    <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      {err && <div className="alert-error">{err}</div>}
      <div className="form-group" style={{ margin: 0 }}>
        <label>{t('asset_type.code')}</label>
        <input value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} required maxLength={255} placeholder="pl. LAPTOP" autoFocus />
      </div>
      <div className="form-group" style={{ margin: 0 }}>
        <label>{t('asset_type.name')}</label>
        <input value={name} onChange={(e) => setName(e.target.value)} required maxLength={255} placeholder="pl. Laptop" />
      </div>
      <div style={{ display: 'flex', gap: 8 }}>
        <button className="btn btn-primary btn-sm" type="submit" disabled={saving || !code.trim() || !name.trim()}>
          {saving ? t('common.saving') : t('common.save')}
        </button>
        <button className="btn btn-secondary btn-sm" type="button" onClick={onCancel}>{t('common.cancel')}</button>
      </div>
    </form>
  )
}

export default function AssetTypePage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()

  const [list, setList]       = useState([])
  const [loading, setLoading] = useState(true)
  const [showCreate, setShowCreate] = useState(false)
  const [saving, setSaving]   = useState(false)

  async function load() {
    setLoading(true)
    try {
      const res = await assetTypes.list()
      setList(res.data.data ?? [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  async function handleCreate(code, name) {
    setSaving(true)
    try {
      await assetTypes.create({ code, name })
      setShowCreate(false)
      toast(t('asset_type.created'), 'success')
      load()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('asset_type.title')}</h1>
        {can('asset.create') && !showCreate && (
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ {t('asset_type.new')}</button>
        )}
      </div>

      {showCreate && can('asset.create') && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong style={{ display: 'block', marginBottom: 12 }}>{t('asset_type.new')}</strong>
          <TypeForm onSave={handleCreate} onCancel={() => setShowCreate(false)} saving={saving} />
        </div>
      )}

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>{t('asset_type.code')}</th>
              <th>{t('asset_type.name')}</th>
              <th>{t('asset_type.scope')}</th>
            </tr>
          </thead>
          <tbody>
            {list.length === 0 && (
              <tr><td colSpan={3} className="text-muted">{t('asset_type.no_types')}</td></tr>
            )}
            {list.map((tp) => (
              <tr key={tp.id}>
                <td><code style={{ fontSize: 13 }}>{tp.code}</code></td>
                <td>{tp.name}</td>
                <td className="text-muted" style={{ fontSize: 13 }}>
                  {tp.is_global ? t('asset_type.scope_global') : t('asset_type.scope_own')}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
