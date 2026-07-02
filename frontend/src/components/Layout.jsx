import { useState, useEffect } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { useTranslation } from '../contexts/TranslationContext'
import CompanySwitcher from './CompanySwitcher'
import {
  FileText, Users2, Package,
  UserRound, Users, Building2, ScrollText, Hash, Languages, Sliders, Layers,
  Settings2, ChevronLeft, ChevronRight,
  LogOut, Moon, Sun,
} from 'lucide-react'

export default function Layout() {
  const { user, logout, can }               = useAuth()
  const { locale, setLocale, t, SUPPORTED } = useTranslation()
  const navigate                            = useNavigate()

  const [sidebarOpen, setSidebarOpen] = useState(
    () => localStorage.getItem('sidebarOpen') !== 'false'
  )
  const [theme, setTheme] = useState(
    () => localStorage.getItem('theme') ?? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
  )

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme)
  }, [theme])

  function toggleTheme() {
    setTheme((prev) => {
      const next = prev === 'dark' ? 'light' : 'dark'
      localStorage.setItem('theme', next)
      return next
    })
  }

  function toggleSidebar() {
    setSidebarOpen((prev) => {
      const next = !prev
      localStorage.setItem('sidebarOpen', String(next))
      return next
    })
  }

  async function handleLogout() { await logout(); navigate('/login') }
  async function handleLocale(loc) { await setLocale(loc, true) }

  const topNavItems = [
    { to: '/documents', label: t('nav.documents'), icon: FileText, anyPerm: ['invoice.view', 'receipt.view'] },
    { to: '/partners',  label: t('nav.partners'),  icon: Users2,   perm: 'partner.view' },
    { to: '/products',  label: t('nav.products'),  icon: Package,  perm: 'product.view' },
  ]

  const settingsItems = [
    { to: '/companies',                label: 'Cégek',                icon: Layers,     superadminOnly: true },
    { to: '/users',                    label: t('nav.users'),         icon: UserRound,  perm: 'user.view' },
    { to: '/groups',                   label: t('nav.groups'),        icon: Users,      perm: 'group.view' },
    { to: '/company',                  label: t('nav.company'),       icon: Building2,  perm: 'company.view' },
    { to: '/audit-logs',               label: t('nav.audit_log'),     icon: ScrollText, perm: 'audit.view' },
    { to: '/settings/document-series', label: t('nav.doc_series'),    icon: Hash,       perm: 'document_series.manage' },
    { to: '/settings/translations',    label: t('nav.translations'),  icon: Languages,  perm: 'company.manage' },
    { to: '/settings/custom-fields',   label: t('nav.custom_fields'), icon: Sliders,    perm: 'company.manage' },
  ]

  const visibleTop      = topNavItems.filter((i) => i.anyPerm ? i.anyPerm.some((p) => can(p)) : can(i.perm))
  const visibleSettings = settingsItems.filter((i) =>
    i.superadminOnly ? user?.is_superadmin : can(i.perm)
  )
  const collapsed       = !sidebarOpen

  return (
    <div className="layout">
      <aside className={`sidebar${collapsed ? ' sidebar--collapsed' : ''}`}>

        {/* Fejléc: brand bal oldalon, összecsukó jobb oldalon */}
        <div className="sidebar-header">
          <span className="sidebar-brand-text">{collapsed ? 'E' : 'ERP'}</span>
          <button className="sidebar-collapse-btn" onClick={toggleSidebar}
            title={t('nav.collapse')}>
            {collapsed ? <ChevronRight size={14} /> : <ChevronLeft size={14} />}
          </button>
        </div>

        {!collapsed && <CompanySwitcher />}

        <nav className="sidebar-nav">
          {visibleTop.map((i) => (
            <NavLink key={i.to} to={i.to} title={collapsed ? i.label : undefined}
              className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}>
              <i.icon size={16} className="nav-icon" />
              {!collapsed && <span>{i.label}</span>}
            </NavLink>
          ))}

          {visibleSettings.length > 0 && (
            <>
              {collapsed
                ? <div className="nav-divider" />
                : <div className="nav-section-label">
                    <Settings2 size={11} style={{ flexShrink: 0 }} />
                    <span>{t('nav.settings')}</span>
                  </div>
              }
              {visibleSettings.map((i) => (
                <NavLink key={i.to} to={i.to} title={collapsed ? i.label : undefined}
                  className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}>
                  <i.icon size={16} className="nav-icon" />
                  {!collapsed && <span>{i.label}</span>}
                </NavLink>
              ))}
            </>
          )}
        </nav>

        {/* Footer: nyelv + téma + kijelentkezés */}
        <div className={`sidebar-footer${collapsed ? ' sidebar-footer--collapsed' : ''}`}>
          {!collapsed ? (
            <>
              <div className="sidebar-lang-row">
                {SUPPORTED.map((loc) => (
                  <button key={loc}
                    className={`lang-btn${locale === loc ? ' active' : ''}`}
                    onClick={() => handleLocale(loc)}
                    title={t(`translation.${loc}`)}>
                    {loc.toUpperCase()}
                  </button>
                ))}
                <button className="theme-toggle-btn" onClick={toggleTheme}
                  title={theme === 'dark' ? t('nav.light_mode') : t('nav.dark_mode')}>
                  {theme === 'dark' ? <Sun size={13} /> : <Moon size={13} />}
                </button>
              </div>
              <div className="sidebar-user-row">
                <span className="user-name">{user?.name}</span>
                <button className="btn-link sidebar-logout" onClick={handleLogout} title={t('nav.logout')}>
                  <LogOut size={14} />
                  <span>{t('nav.logout')}</span>
                </button>
              </div>
            </>
          ) : (
            <>
              <button className="theme-toggle-btn" onClick={toggleTheme}
                title={theme === 'dark' ? t('nav.light_mode') : t('nav.dark_mode')}>
                {theme === 'dark' ? <Sun size={13} /> : <Moon size={13} />}
              </button>
              <button className="btn-link sidebar-logout" onClick={handleLogout} title={t('nav.logout')}>
                <LogOut size={14} />
              </button>
            </>
          )}
        </div>

      </aside>
      <main className="main-content">
        <Outlet />
      </main>
    </div>
  )
}
