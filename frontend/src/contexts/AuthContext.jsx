import { createContext, useContext, useEffect, useState } from 'react'
import { me, login as apiLogin, logout as apiLogout, switchCompany as apiSwitch } from '../api/auth'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [companies, setCompanies] = useState([])
  const [activeCompanyId, setActiveCompanyId] = useState(null)
  const [permissions, setPermissions] = useState([])
  const [loading, setLoading] = useState(true)

  async function fetchMe() {
    try {
      const res = await me()
      const { user, companies, active_company_id, permissions } = res.data
      setUser(user)
      setCompanies(companies)
      setActiveCompanyId(active_company_id)
      setPermissions(permissions ?? [])
    } catch {
      setUser(null)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { fetchMe() }, [])

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
    <AuthContext.Provider value={{ user, companies, activeCompanyId, permissions, loading, login, logout, switchCompany, can }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
