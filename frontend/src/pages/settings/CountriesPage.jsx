import { useEffect, useMemo, useState } from 'react'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { countries as countriesApi } from '../../api/countries'

function normalize(str) {
  return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

export default function CountriesPage() {
  const { user } = useAuth()
  const { t, locale } = useTranslation()
  const toast = useToast()

  const [list, setList]           = useState([])
  const [selected, setSelected]   = useState(new Set())
  const [search, setSearch]       = useState('')
  const [loading, setLoading]     = useState(true)
  const [saving, setSaving]       = useState(false)

  const regionNames = useMemo(
    () => new Intl.DisplayNames([locale], { type: 'region' }),
    [locale]
  )

  async function load() {
    setLoading(true)
    try {
      const res = await countriesApi.adminList()
      const data = res.data.data ?? []
      setList(data)
      setSelected(new Set(data.filter((c) => c.enabled).map((c) => c.code)))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const withNames = useMemo(
    () => list.map((c) => ({ ...c, name: regionNames.of(c.code) ?? c.code })),
    [list, regionNames]
  )

  const sorted = useMemo(
    () => [...withNames].sort((a, b) => a.name.localeCompare(b.name, locale, { sensitivity: 'base' })),
    [withNames, locale]
  )

  const filtered = useMemo(() => {
    if (!search.trim()) return sorted
    const needle = normalize(search.trim())
    return sorted.filter((c) => normalize(c.name).includes(needle) || normalize(c.code).includes(needle))
  }, [sorted, search])

  function toggle(code) {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(code)) next.delete(code); else next.add(code)
      return next
    })
  }

  function selectAllFiltered() {
    setSelected((prev) => {
      const next = new Set(prev)
      filtered.forEach((c) => next.add(c.code))
      return next
    })
  }

  function deselectAllFiltered() {
    setSelected((prev) => {
      const next = new Set(prev)
      filtered.forEach((c) => next.delete(c.code))
      return next
    })
  }

  async function handleSave() {
    setSaving(true)
    try {
      const res = await countriesApi.adminUpdate(Array.from(selected))
      const data = res.data.data ?? []
      setList(data)
      setSelected(new Set(data.filter((c) => c.enabled).map((c) => c.code)))
      toast(t('country.save_success'), 'success')
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    } finally {
      setSaving(false)
    }
  }

  if (!user?.is_superadmin) {
    return <p className="text-muted">{t('common.no_permission')}</p>
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('country.title')}</h1>
        <button className="btn btn-primary" onClick={handleSave} disabled={saving || loading}>
          {saving ? t('common.saving') : t('common.save')}
        </button>
      </div>

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <>
          <div className="card" style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 12, marginBottom: 16 }}>
            <input
              type="text"
              placeholder={t('country.search_placeholder')}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              style={{ maxWidth: 320 }}
            />
            <button className="btn btn-secondary btn-sm" onClick={selectAllFiltered}>{t('country.select_all')}</button>
            <button className="btn btn-secondary btn-sm" onClick={deselectAllFiltered}>{t('country.deselect_all')}</button>
            <span className="text-muted" style={{ fontSize: 13, marginLeft: 'auto' }}>
              {t('country.selected_count', { selected: selected.size, total: list.length })}
            </span>
          </div>

          <div
            className="card"
            style={{
              maxHeight: '60vh',
              overflowY: 'auto',
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))',
              gap: 4,
              alignContent: 'start',
            }}
          >
            {filtered.length === 0 && (
              <p className="text-muted" style={{ gridColumn: '1 / -1' }}>{t('country.no_results')}</p>
            )}
            {filtered.map((c) => (
              <label
                key={c.code}
                style={{
                  display: 'flex', alignItems: 'center', gap: 8,
                  padding: '5px 6px', borderRadius: 5, cursor: 'pointer',
                  fontSize: 13,
                }}
                onMouseEnter={(e) => { e.currentTarget.style.background = 'var(--color-hover)' }}
                onMouseLeave={(e) => { e.currentTarget.style.background = 'transparent' }}
              >
                <input
                  type="checkbox"
                  checked={selected.has(c.code)}
                  onChange={() => toggle(c.code)}
                  style={{ width: 'auto', flexShrink: 0 }}
                />
                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{c.name}</span>
                <span className="text-muted" style={{ fontSize: 11, marginLeft: 'auto', flexShrink: 0 }}>{c.code}</span>
              </label>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
