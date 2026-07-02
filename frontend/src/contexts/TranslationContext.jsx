import { createContext, useContext, useState, useCallback } from 'react'
import { translationsApi } from '../api/translations'

const TranslationContext = createContext(null)

const SUPPORTED = ['hu', 'en', 'de']

function storedLocale() {
  const v = localStorage.getItem('locale')
  return SUPPORTED.includes(v) ? v : 'hu'
}

export function TranslationProvider({ children }) {
  const [locale, setLocaleState]   = useState(storedLocale)
  const [strings, setStrings]      = useState({})
  const [loaded, setLoaded]        = useState(false)

  const loadLocale = useCallback(async (loc) => {
    try {
      const res = await translationsApi.forLocale(loc)
      setStrings(res.data)
    } catch {
      // hálózati hiba esetén üres szótárral folytatunk
    } finally {
      setLoaded(true)
    }
  }, [])

  // Locale beállítása (login után, vagy manuális váltáskor)
  async function setLocale(loc, saveToServer = false) {
    if (!SUPPORTED.includes(loc)) loc = 'hu'
    localStorage.setItem('locale', loc)
    setLocaleState(loc)
    await loadLocale(loc)
    if (saveToServer) {
      try { await translationsApi.setLocale(loc) } catch { /* ignore */ }
    }
  }

  // Fordítás kikeresése: "nav.documents" → "Bizonylatok"
  // Ha nincs találat, a kulcsot adja vissza (ezzel is látszik, mi hiányzik)
  function t(key, params = {}) {
    let value = strings[key] ?? key
    Object.entries(params).forEach(([k, v]) => {
      value = value.replace(`{${k}}`, String(v))
    })
    return value
  }

  return (
    <TranslationContext.Provider value={{ locale, setLocale, loadLocale, strings, loaded, t, SUPPORTED }}>
      {children}
    </TranslationContext.Provider>
  )
}

export function useTranslation() {
  const ctx = useContext(TranslationContext)
  if (!ctx) throw new Error('useTranslation must be used within TranslationProvider')
  return ctx
}
