import { useEffect, useMemo, useState } from 'react'
import { countries as countriesApi } from '../api/countries'
import { useTranslation } from '../contexts/TranslationContext'
import SearchableSelect from './SearchableSelect'

/**
 * Country picker built on SearchableSelect. Loads the superadmin-enabled
 * code list from GET /api/countries and resolves display names at runtime
 * via Intl.DisplayNames for the current locale — country names are never
 * hand-translated.
 *
 * Edge case (mirrors JobPositionSelect): if `value` is a code that is no
 * longer in the enabled list (disabled by a superadmin after the partner
 * was created), it is appended as an extra option so it stays visible and
 * round-trips correctly instead of being silently swapped on save.
 */
export default function CountrySelect({ id, value, onChange, disabled = false }) {
  const { t, locale } = useTranslation()
  const [codes, setCodes] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(false)
    countriesApi.list()
      .then((res) => {
        if (!cancelled) setCodes(res.data.data ?? [])
      })
      .catch(() => {
        if (!cancelled) setError(true)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => { cancelled = true }
  }, [])

  const regionNames = useMemo(
    () => new Intl.DisplayNames([locale], { type: 'region' }),
    [locale]
  )

  const options = useMemo(() => {
    const allCodes = value && !codes.includes(value) ? [...codes, value] : codes
    return allCodes
      .map((code) => ({ value: code, label: regionNames.of(code) ?? code }))
      .sort((a, b) => a.label.localeCompare(b.label, locale, { sensitivity: 'base' }))
  }, [codes, value, regionNames, locale])

  return (
    <div>
      <SearchableSelect
        id={id}
        value={value}
        onChange={onChange}
        options={options}
        placeholder={t('country.search_placeholder')}
        noResultsLabel={t('country.no_results')}
        disabled={disabled}
        loading={loading}
      />
      {error && <p className="text-muted" style={{ fontSize: 12, marginTop: 4 }}>{t('common.error')}</p>}
    </div>
  )
}
