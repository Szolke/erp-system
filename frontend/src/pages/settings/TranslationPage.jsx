import { useEffect, useState, useCallback } from 'react'
import { translationsApi } from '../../api/translations'
import { useTranslation } from '../../contexts/TranslationContext'

export default function TranslationPage() {
  const { t } = useTranslation()
  const [rows, setRows]           = useState([])
  const [namespaces, setNs]       = useState([])
  const [ns, setNs2]              = useState('')
  const [search, setSearch]       = useState('')
  const [loading, setLoading]     = useState(true)
  const [saving, setSaving]       = useState({})   // { "ns|key": true }
  const [edited, setEdited]       = useState({})   // { "ns|key": { hu, en, de } }

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await translationsApi.list({ namespace: ns, search })
      setRows(res.data.data)
      setNs(res.data.namespaces)
    } finally {
      setLoading(false)
    }
  }, [ns, search])

  useEffect(() => { load() }, [load])

  function rowKey(row) { return `${row.namespace}|${row.key}` }

  function getVal(row, locale) {
    const k = rowKey(row)
    return edited[k]?.[locale] ?? row[locale] ?? ''
  }

  function handleChange(row, locale, value) {
    const k = rowKey(row)
    setEdited((prev) => ({ ...prev, [k]: { ...prev[k], [locale]: value } }))
  }

  async function handleSave(row) {
    const k = rowKey(row)
    const changes = edited[k]
    if (!changes) return
    setSaving((prev) => ({ ...prev, [k]: true }))
    try {
      await translationsApi.upsert(row.namespace, row.key, changes)
      // Lokális row frissítése
      setRows((prev) => prev.map((r) =>
        rowKey(r) === k ? { ...r, ...changes } : r
      ))
      setEdited((prev) => { const next = { ...prev }; delete next[k]; return next })
    } finally {
      setSaving((prev) => { const next = { ...prev }; delete next[k]; return next })
    }
  }

  const isDirty = (row) => !!edited[rowKey(row)]

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('translation.title')}</h1>
      </div>

      {/* Szűrők */}
      <div className="search-row" style={{ marginBottom: 16 }}>
        <select
          value={ns}
          onChange={(e) => setNs2(e.target.value)}
          style={{ width: 180 }}
        >
          <option value="">{t('translation.all_ns')}</option>
          {namespaces.map((n) => <option key={n} value={n}>{n}</option>)}
        </select>
        <input
          placeholder={t('translation.search_ph')}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          style={{ maxWidth: 280 }}
        />
        <button className="btn btn-secondary" onClick={load}>{t('common.search')}</button>
      </div>

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th style={{ width: 100 }}>{t('translation.namespace')}</th>
              <th style={{ width: 160 }}>{t('translation.key')}</th>
              <th>{t('translation.hu')}</th>
              <th>{t('translation.en')}</th>
              <th>{t('translation.de')}</th>
              <th style={{ width: 80 }}></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const k = rowKey(row)
              return (
                <tr key={k} style={isDirty(row) ? { background: '#fffbeb' } : {}}>
                  <td><span className="badge" style={{ background: '#f1f5f9', color: '#475569' }}>{row.namespace}</span></td>
                  <td style={{ fontFamily: 'monospace', fontSize: 12, color: '#475569' }}>{row.key}</td>
                  {['hu', 'en', 'de'].map((loc) => (
                    <td key={loc}>
                      <input
                        value={getVal(row, loc)}
                        onChange={(e) => handleChange(row, loc, e.target.value)}
                        style={{ fontSize: 13, padding: '4px 8px' }}
                      />
                    </td>
                  ))}
                  <td>
                    <button
                      className="btn btn-primary btn-sm"
                      disabled={!isDirty(row) || saving[k]}
                      onClick={() => handleSave(row)}
                    >
                      {saving[k] ? '…' : t('common.save')}
                    </button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}

      <p className="text-muted mt-4">{t('common.total')}: {rows.length}</p>
    </div>
  )
}
