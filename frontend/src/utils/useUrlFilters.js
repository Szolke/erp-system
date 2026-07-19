import { useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'

/**
 * Egy riport-fül szűrőit tárolja a URL query stringben (megosztható,
 * frissítés-biztos). Hiányzó kulcsokat az induláskor a defaults-ból tölti
 * fel és VISSZAÍRJA a URL-be — enélkül egy megosztott link idővel más
 * időszakot jelentene (pl. "mai hónap" a megnyitás pillanatában eltérő
 * lehet a küldő és a fogadó gépén).
 *
 * @param {Record<string, string>} defaults
 * @returns {[Record<string, string>, (patch: Record<string, string|null>) => void]}
 */
export function useUrlFilters(defaults) {
  const [searchParams, setSearchParams] = useSearchParams()

  useEffect(() => {
    const missingSome = Object.keys(defaults).some((key) => !searchParams.has(key))
    if (missingSome) {
      const next = new URLSearchParams(searchParams)
      Object.entries(defaults).forEach(([key, value]) => {
        if (!next.has(key)) next.set(key, String(value))
      })
      setSearchParams(next, { replace: true })
    }
    // Csak induláskor — a defaults objektum-referencia hívónként új lehet.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const filters = {}
  Object.keys(defaults).forEach((key) => {
    filters[key] = searchParams.has(key) ? searchParams.get(key) : defaults[key]
  })

  function setFilters(patch) {
    const next = new URLSearchParams(searchParams)
    Object.entries(patch).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') next.delete(key)
      else next.set(key, String(value))
    })
    setSearchParams(next, { replace: true })
  }

  return [filters, setFilters]
}
