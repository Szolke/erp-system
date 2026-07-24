import { useState, useEffect, Suspense } from 'react'
import { NavLink, Link, Outlet, useNavigate, useLocation } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { useTranslation } from '../contexts/TranslationContext'
import { company as companyApi } from '../api/company'
import CompanySwitcher from './CompanySwitcher'
import RouteErrorBoundary from './RouteErrorBoundary'
import { applySidebarTheme } from '../utils/sidebarTheme'
import {
  LayoutDashboard, FileText, Users2, Package, BookOpen,
  UserRound, Users, Building2, ScrollText, Hash, Languages, Sliders, Layers, Blocks,
  Settings2, ChevronLeft, ChevronRight,
  LogOut, Moon, Sun, Terminal, UsersRound, Boxes, Tags, Briefcase, Globe, BarChart3,
  AlertTriangle, Receipt, FileSpreadsheet,
} from 'lucide-react'

export default function Layout() {
  const { user, logout, can, activeCompanyId } = useAuth()
  const { locale, setLocale, t, SUPPORTED } = useTranslation()
  const navigate                            = useNavigate()
  const { pathname }                        = useLocation()

  const [sidebarOpen, setSidebarOpen] = useState(
    () => localStorage.getItem('sidebarOpen') !== 'false'
  )
  const [theme, setTheme] = useState(
    () => localStorage.getItem('theme') ?? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
  )

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme)
  }, [theme])

  // A `.main-content`-en lévő `overflow-y: auto` a gyakorlatban SOHA nem lép
  // életbe: a `.layout` `min-height: 100vh`-t használ (nem `height`-t), ezért
  // hosszú tartalomnál a teljes `.layout` (és vele a `.main-content` is)
  // magasabbra nő a viewportnál — a tényleges görgetés a böngészőablak
  // (window) szintjén történik, ezért ott kell visszaállítani útvonalváltáskor
  // (pl. sidebar-menüre kattintva).
  useEffect(() => {
    window.scrollTo(0, 0)
  }, [pathname])

  useEffect(() => {
    if (!activeCompanyId) return
    companyApi.settings.getAll()
      .then((res) => {
        const s = res.data.data.find((x) => x.key === 'sidebar_accent_color')
        applySidebarTheme(s?.value ?? '#1e293b')
      })
      .catch(() => {})
  }, [activeCompanyId])

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
    { to: '/',          label: t('nav.dashboard'), icon: LayoutDashboard, public: true },
    { to: '/documents', label: t('nav.documents'), icon: FileText,  anyPerm: ['invoice.view', 'receipt.view'] },
    { to: '/reports',   label: t('nav.reports'),   icon: BarChart3, perm: 'report.view' },
    { to: '/partners',  label: t('nav.partners'),  icon: Users2,    perm: 'partner.view' },
    { to: '/products',  label: t('nav.products'),  icon: Package,   perm: 'product.view' },
    { to: '/assets',    label: t('nav.assets'),    icon: Boxes,     perm: 'asset.view' },
    { to: '/enyugta/reports', label: 'eNyugta jelentések', icon: Receipt, perm: 'enyugta.view' },
    { to: '/wiki',      label: t('nav.wiki'),       icon: BookOpen,  public: true },
  ]

  const settingsGroups = [
    {
      label: t('nav.settings_company_data'),
      items: [
        { to: '/companies',                label: 'Cégek',              icon: Layers, superadminOnly: true },
        { to: '/company',                  label: t('nav.company'),     icon: Building2, perm: 'company.view' },
        { to: '/settings/document-series', label: t('nav.doc_series'),  icon: Hash,   perm: 'document_series.manage' },
        { to: '/settings/enyugta',         label: 'NAV eNyugta',        icon: FileSpreadsheet, perm: 'enyugta.view' },
      ],
    },
    {
      label: t('nav.settings_general'),
      items: [
        { to: '/settings/modules',         label: 'Modulok',              icon: Blocks,     superadminOnly: true },
        { to: '/audit-logs',               label: t('nav.audit_log'),     icon: ScrollText, perm: 'audit.view' },
        { to: '/nav-submissions',          label: t('nav.nav_submissions'), icon: AlertTriangle, perm: 'nav.log.view' },
        { to: '/settings/custom-fields',   label: t('nav.custom_fields'), icon: Sliders,    perm: 'company.manage' },
        { to: '/settings/api-tester',      label: t('nav.api_tester'),    icon: Terminal,   perm: 'api_tester.use' },
      ],
    },
    {
      label: t('nav.settings_users'),
      items: [
        { to: '/users',  label: t('nav.users'),  icon: UserRound, perm: 'user.view' },
        { to: '/groups', label: t('nav.groups'), icon: Users,     perm: 'group.view' },
      ],
    },
    {
      label: t('nav.settings_dictionaries'),
      items: [
        { to: '/settings/countries',     label: t('nav.countries'),     icon: Globe,      superadminOnly: true },
        { to: '/settings/job-positions', label: 'Munkakörök',           icon: Briefcase,  perm: 'job_position.manage' },
        { to: '/settings/asset-types',   label: t('nav.asset_types'),   icon: Tags,       perm: 'asset.view' },
        { to: '/settings/sales-groups',  label: 'Értékesítő csoportok', icon: UsersRound, perm: 'sales_group.view' },
        { to: '/settings/translations',  label: t('nav.translations'),  icon: Languages,  perm: 'company.manage' },
      ],
    },
  ]

  const visibleTop = topNavItems.filter((i) => i.public || (i.anyPerm ? i.anyPerm.some((p) => can(p)) : can(i.perm)))
  const visibleSettingsGroups = settingsGroups
    .map((g) => ({ ...g, items: g.items.filter((i) => (i.superadminOnly ? user?.is_superadmin : can(i.perm))) }))
    .filter((g) => g.items.length > 0)
  const hasVisibleSettings = visibleSettingsGroups.some((g) => g.items.length > 0)
  const collapsed = !sidebarOpen

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

          {hasVisibleSettings && (
            <>
              {collapsed
                ? <div className="nav-divider" />
                : <div className="nav-section-label">
                    <Settings2 size={11} style={{ flexShrink: 0 }} />
                    <span>{t('nav.settings')}</span>
                  </div>
              }
              {visibleSettingsGroups.map((g) => (
                <div key={g.label}>
                  {!collapsed && <div className="nav-subsection-label">{g.label}</div>}
                  {g.items.map((i) => (
                    <NavLink key={i.to} to={i.to} title={collapsed ? i.label : undefined}
                      className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}>
                      <i.icon size={16} className="nav-icon" />
                      {!collapsed && <span>{i.label}</span>}
                    </NavLink>
                  ))}
                </div>
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
                <Link to={`/users/${user?.id}`} className="sidebar-profile-link" title={t('nav.my_profile')}>
                  <UserRound size={12} className="nav-icon" />
                  <span className="user-name">{user?.name}</span>
                </Link>
                <button className="btn-link sidebar-logout" onClick={handleLogout} title={t('nav.logout')}>
                  <LogOut size={14} />
                  <span>{t('nav.logout')}</span>
                </button>
              </div>
            </>
          ) : (
            <>
              <Link to={`/users/${user?.id}`} className="sidebar-profile-link" title={t('nav.my_profile')}>
                <UserRound size={14} />
              </Link>
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
        {/* `key={pathname}` az ErrorBoundary-n: navigációkor új példány jön létre,
            különben egy render-hiba után a felhasználó a hibaképernyőn ragadna. */}
        <RouteErrorBoundary key={pathname}>
          <Suspense fallback={<p className="text-muted">{t('common.loading')}</p>}>
            <Outlet key={activeCompanyId} />
          </Suspense>
        </RouteErrorBoundary>
      </main>
    </div>
  )
}
