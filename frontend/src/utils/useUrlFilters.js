import { useEffect, useRef } from 'react'
import { useSearchParams } from 'react-router-dom'

/**
 * Egy riport-fül szűrőit tárolja a URL query stringben (megosztható,
 * frissítés-biztos). Hiányzó kulcsokat az induláskor a defaults-ból tölti
 * fel és VISSZAÍRJA a URL-be — enélkül egy megosztott link idővel más
 * időszakot jelentene (pl. "mai hónap" a megnyitás pillanatában eltérő
 * lehet a küldő és a fogadó gépén).
 *
 * A harmadik visszatérési érték azoknak a kulcsoknak a halmaza, amelyek a
 * megnyitás pillanatában TÉNYLEGESEN a URL-ben álltak — a defaultokból
 * feltöltöttek nélkül. Erre azért van szükség, mert a fenti feltöltés után a
 * `filters` már nem árulja el, hogy egy érték megosztott linkből jött-e vagy a
 * default visszaírásából; a lapméret-feloldásnál (URL > mentett preferencia >
 * default) viszont pontosan ez a különbség dönt.
 *
 * @param {Record<string, string>} defaults
 * @returns {[Record<string, string>, (patch: Record<string, string|null>) => void, Set<string>]}
 */
export function useUrlFilters(defaults) {
  const [searchParams, setSearchParams] = useSearchParams()

  // Az első renderben rögzítjük — a feltöltő effect ekkor még nem futott le.
  const explicitKeysRef = useRef(null)
  if (explicitKeysRef.current === null) {
    explicitKeysRef.current = new Set(searchParams.keys())
  }

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

  // Figyelem: a patch a render-időben látott `searchParams`-ra épül. Ha
  // ugyanabban a commitban a fenti default-feltöltés is ír (pl. a listaoldalak
  // mount-jánál, ahol a mentett lapméret is a URL-be szinkronizálódik), akkor a
  // második írás nyer, és a feltöltött — kizárólag ÜRES értékű — default
  // kulcsok kimaradnak a címsorból. A megnyitáskor ténylegesen a URL-ben álló
  // kulcsok viszont megmaradnak, és a `filters` értékei sem változnak, mert a
  // hiányzó kulcsokra úgyis a defaults felel. (A react-router funkcionális
  // setSearchParams-ja itt nem segítene: a callback is a render-időben rögzített
  // query-t kapja meg, nem a commit közben már megírtat.)
  function setFilters(patch) {
    const next = new URLSearchParams(searchParams)
    Object.entries(patch).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') next.delete(key)
      else next.set(key, String(value))
    })
    setSearchParams(next, { replace: true })
  }

  return [filters, setFilters, explicitKeysRef.current]
}
