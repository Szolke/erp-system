import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import CompanySwitcher from './CompanySwitcher'

const navItems = [
  { to: '/invoices', label: 'Számlák', perm: 'invoice.view' },
  { to: '/receipts', label: 'Nyugták', perm: 'receipt.view' },
  { to: '/partners', label: 'Partnerek', perm: 'partner.view' },
  { to: '/products', label: 'Termékek', perm: 'product.view' },
  { to: '/company', label: 'Cégbeállítások', perm: 'company.view' },
  { to: '/audit-logs', label: 'Audit napló', perm: 'audit.view' },
]

export default function Layout() {
  const { user, logout, can } = useAuth()
  const navigate = useNavigate()

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  return (
    <div className="layout">
      <aside className="sidebar">
        <div className="sidebar-brand">ERP</div>
        <CompanySwitcher />
        <nav className="sidebar-nav">
          {navItems.filter((i) => can(i.perm)).map((i) => (
            <NavLink key={i.to} to={i.to} className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}>
              {i.label}
            </NavLink>
          ))}
        </nav>
        <div className="sidebar-footer">
          <span className="user-name">{user?.name}</span>
          <button className="btn-link" onClick={handleLogout}>Kijelentkezés</button>
        </div>
      </aside>
      <main className="main-content">
        <Outlet />
      </main>
    </div>
  )
}
