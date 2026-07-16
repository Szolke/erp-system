import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { assets, assetTypes } from '../../api/assets'
import { useTranslation } from '../../contexts/TranslationContext'

const empty = { serial_number: '', imei: '', asset_type_id: '', status: 'active' }

const STATUSES = ['active', 'issued', 'service', 'scrapped']

export default function AssetFormPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { t } = useTranslation()
  const isEdit = !!id
  const [form, setForm]   = useState(empty)
  const [name, setName]   = useState(null) // szerver-generált — csak megjelenítéshez
  const [types, setTypes] = useState([])
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    assetTypes.list().then((res) => {
      const list = res.data.data ?? []
      setTypes(list)
      if (!isEdit && list[0]) setForm((f) => ({ ...f, asset_type_id: list[0].id }))
    })
    if (isEdit) {
      assets.get(id).then((res) => {
        const a = res.data.data
        setForm({ serial_number: a.serial_number, imei: a.imei ?? '', asset_type_id: a.asset_type_id, status: a.status })
        setName(a.name)
      })
    }
  }, [id])

  function setField(k, v) { setForm((f) => ({ ...f, [k]: v })) }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSaving(true)
    try {
      if (isEdit) {
        // A name és az asset_type_id a backend szerint módosíthatatlan (server-generated
        // name, típusváltás inkonzisztenssé tenné) — a UI ezt tükrözi: nem küldi vissza.
        await assets.update(id, {
          serial_number: form.serial_number,
          imei: form.imei || null,
          status: form.status,
        })
      } else {
        await assets.create({
          serial_number: form.serial_number,
          imei: form.imei || null,
          asset_type_id: form.asset_type_id,
        })
      }
      navigate('/assets')
    } catch (err) {
      const errs = err.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join(' | ') : err.response?.data?.message ?? 'Hiba')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{isEdit ? `${t('asset.title')} – ${t('common.edit')}` : t('asset.new')}</h1>
        <Link to="/assets" className="btn btn-secondary">{t('common.back')}</Link>
      </div>
      {error && <div className="alert-error mb-4">{error}</div>}
      <form onSubmit={handleSubmit}>
        <div className="card" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          {isEdit && (
            <div className="form-group" style={{ gridColumn: '1/-1' }}>
              <label>{t('asset.name')}</label>
              <input value={name ?? ''} disabled readOnly />
            </div>
          )}
          <div className="form-group">
            <label>{t('asset.serial_number')}</label>
            <input value={form.serial_number} onChange={(e) => setField('serial_number', e.target.value)} required />
          </div>
          <div className="form-group">
            <label>{t('asset.imei')}</label>
            <input value={form.imei} onChange={(e) => setField('imei', e.target.value)} />
          </div>
          <div className="form-group">
            <label>{t('asset.asset_type')}</label>
            <select value={form.asset_type_id} onChange={(e) => setField('asset_type_id', e.target.value)} required disabled={isEdit}>
              {types.map((tp) => <option key={tp.id} value={tp.id}>{tp.name} ({tp.code})</option>)}
            </select>
          </div>
          {isEdit && (
            <div className="form-group">
              <label>{t('asset.status')}</label>
              <select value={form.status} onChange={(e) => setField('status', e.target.value)} required>
                {STATUSES.map((s) => <option key={s} value={s}>{t(`asset.status_${s}`)}</option>)}
              </select>
            </div>
          )}
        </div>
        {!isEdit && (
          <p className="text-muted" style={{ fontSize: 13, marginTop: 8 }}>{t('asset.name_hint')}</p>
        )}
        <div className="flex">
          <button className="btn btn-primary" type="submit" disabled={saving}>{saving ? t('common.saving') : t('common.save')}</button>
          <Link to="/assets" className="btn btn-secondary">{t('common.cancel')}</Link>
        </div>
      </form>
    </div>
  )
}
