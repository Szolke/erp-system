import { useState, useEffect } from 'react'
import { NavLink, Outlet, useNavigate, useLocation } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { useTranslation } from '../contexts/TranslationContext'
import CompanySwitcher from './CompanySwitcher'
import {
  FileText, Users2, Package,
  UserRound, Users, Building2, ScrollText, Hash, Languages,
  Settings2, ChevronDown, ChevronRight, ChevronLeft,
  LogOut,
} from 'lucide-react'

const SETTINGS_PATHS = ['/users', '/groups', '/company', '/audit-logs', '/settings']

export default function Layout() {
  const { user, logout, can }                  = useAuth()
  const { locale, setLocale, t, SUPPORTED }    = useTranslation()
  const navigate                               = useNavigate()
  const location                               = useLocation()

  const isInSettings = SETTINGS_PATHS.some((p) => location.pathname.startsWith(p))

  const [settingsOpen, setSettingsOpen] = useState(isInSettings)
  const [sidebarOpen, setSidebarOpen]   = useState(
    () => localStorage.getItem('sidebarOpen') !== 'false'
  )

  useEffect(() => { if (isInSettings) setSettingsOpen(true) }, [isInSettings])

  function toggleSidebar() {
    setSidebarOpen((prev) => {
      const next = !prev
      localStorage.setItem('sidebarOpen', String(next))
      return next
    })
  }

  async function handleLogout() { await logout(); navigate('/login') }

  async function handleLocale(loc) {
    await setLocale(loc, true) // true = mentés a szerverre is
  }

  const topNavItems = [
    { to: '/documents',  label: t('nav.documents'), icon: FileText, anyPerm: ['invoice.view', 'receipt.view'] },
    { to: '/partners',   label: t('nav.partners'),  icon: Users2,   perm: 'partner.view' },
    { to: '/products',   label: t('nav.products'),  icon: Package,  perm: 'product.view' },
  ]

  const settingsChildren = [
    { to: '/users',                    label: t('nav.users'),        icon: UserRound,  perm: 'user.view' },
    { to: '/groups',                   label: t('nav.groups'),       icon: Users,      perm: 'group.view' },
    { to: '/company',                  label: t('nav.company'),      icon: Building2,  perm: 'company.view' },
    { to: '/audit-logs',               label: t('nav.audit_log'),    icon: ScrollText, perm: 'audit.view' },
    { to: '/settings/document-series', label: t('nav.doc_series'),   icon: Hash,       perm: 'document_series.manage' },
    { to: '/settings/translations',    label: t('nav.translations'), icon: Languages,  perm: 'company.manage' },
  ]

  const visibleTop      = topNavItems.filter((i) => i.anyPerm ? i.anyPerm.some((p) => can(p)) : can(i.perm))
  const visibleSettings = settingsChildren.filter((i) => can(i.perm))
  const collapsed       = !sidebarOpen

  return (
    <div className="layout">
      <aside className={`sidebar${collapsed ? ' sidebar--collapsed' : ''}`}>
        <div className="sidebar-brand">{collapsed ? 'E' : 'ERP'}</div>

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
            collapsed ? (
              <>
                <div className="nav-divider" />
                {visibleSettings.map((i) => (
                  <NavLink key={i.to} to={i.to} title={i.label}
                    className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}>
                    <i.icon size={16} className="nav-icon" />
                  </NavLink>
                ))}
              </>
            ) : (
              <div className="nav-group">
                <button className={'nav-group-header' + (isInSettings ? ' has-active' : '')}
                  onClick={() => setSettingsOpen((o) => !o)}>
                  <Settings2 size={16} className="nav-icon" />
                  <span>{t('nav.settings')}</span>
                  {settingsOpen
                    ? <ChevronDown size={13} className="nav-group-arrow" />
                    : <ChevronRight size={13} className="nav-group-arrow" />}
                </button>
                {settingsOpen && (
                  <div className="nav-subnav">
                    {visibleSettings.map((i) => (
                      <NavLink key={i.to} to={i.to}
                        className={({ isActive }) => 'nav-sublink' + (isActive ? ' active' : '')}>
                        <i.icon size={14} className="nav-icon" />
                        <span>{i.label}</span>
                      </NavLink>
                    ))}
                  </div>
                )}
              </div>
            )
          )}
        </nav>

        {/* Nyelvváltó */}
        <div className={`lang-switcher${collapsed ? ' lang-switcher--collapsed' : ''}`}>
          {SUPPORTED.map((loc) => (
            <button key={loc}
              className={`lang-btn${locale === loc ? ' active' : ''}`}
              onClick={() => handleLocale(loc)}
              title={t(`translation.${loc}`)}>
              {loc.toUpperCase()}
            </button>
          ))}
        </div>

        <button className="sidebar-toggle" onClick={toggleSidebar}
          title={collapsed ? t('nav.collapse') : t('nav.collapse')}>
          {collapsed
            ? <ChevronRight size={15} />
            : <><ChevronLeft size={15} /><span>{t('nav.collapse')}</span></>}
        </button>

        <div className="sidebar-footer">
          {!collapsed && <span className="user-name">{user?.name}</span>}
          <button className="btn-link sidebar-logout" onClick={handleLogout} title={t('nav.logout')}>
            <LogOut size={14} />
            {!collapsed && <span>{t('nav.logout')}</span>}
          </button>
        </div>
      </aside>
      <main className="main-content">
        <Outlet />
      </main>
    </div>
  )
}
