import { createContext, useContext, useEffect, useState } from 'react'
import { me, login as apiLogin, logout as apiLogout, switchCompany as apiSwitch } from '../api/auth'
import { useTranslation } from './TranslationContext'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const { setLocale, loadLocale } = useTranslation()
  const [user, setUser]                     = useState(null)
  const [companies, setCompanies]           = useState([])
  const [activeCompanyId, setActiveCompanyId] = useState(null)
  const [permissions, setPermissions]       = useState([])
  const [listPreferences, setListPreferences] = useState({})
  const [loading, setLoading]               = useState(true)

  async function fetchMe() {
    try {
      const res = await me()
      const { user, companies, active_company_id, permissions, list_preferences } = res.data
      setUser(user)
      setCompanies(companies)
      setActiveCompanyId(active_company_id)
      setPermissions(permissions ?? [])
      setListPreferences(list_preferences ?? {})
      // Fordítások betöltése a user locale-ja szerint (saveToServer=false, már mentve van)
      await setLocale(user.locale ?? 'hu', false)
    } catch {
      setUser(null)
      setListPreferences({})
      // Bejelentkezés előtt is betöltjük a tárolt locale fordításait
      await loadLocale(localStorage.getItem('locale') ?? 'hu')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { fetchMe() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function login(email, password) {
    await apiLogin(email, password)
    await fetchMe()
  }

  async function logout() {
    await apiLogout()
    setUser(null)
    setCompanies([])
    setActiveCompanyId(null)
    setPermissions([])
    setListPreferences({})
  }

  async function switchCompany(companyId) {
    await apiSwitch(companyId)
    setActiveCompanyId(companyId)
    await fetchMe()
  }

  function can(permissionKey) {
    return permissions.includes(permissionKey)
  }

  return (
    <AuthContext.Provider value={{ user, companies, activeCompanyId, permissions, listPreferences, loading, login, logout, switchCompany, can, refreshAuth: fetchMe }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
