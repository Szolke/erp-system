import { lazy } from 'react'
import { Routes, Route } from 'react-router-dom'
import Layout from './components/Layout'
import ProtectedRoute from './components/ProtectedRoute'
import RequirePermission from './components/RequirePermission'
import { ROUTE_ACCESS } from './routePermissions'
import LoginPage from './pages/LoginPage'
import DashboardPage from './pages/DashboardPage'

// A Login (mindig kell, nem-authentikált látogatóknál az első képernyő) és a Dashboard
// (index route, a legtöbb session első képernyője bejelentkezés után; kicsi, nincs nehéz
// függősége) szándékosan EAGER marad — a szétbontásuk csak egy plusz hálózati kört adna
// haszon nélkül. A többi route lazy: route-alapú code splitting, l. docs/progress.md.
const DocumentListPage = lazy(() => import('./pages/documents/DocumentListPage'))
const InvoiceListPage = lazy(() => import('./pages/invoices/InvoiceListPage'))
const InvoiceDetailPage = lazy(() => import('./pages/invoices/InvoiceDetailPage'))
const InvoiceCreatePage = lazy(() => import('./pages/invoices/InvoiceCreatePage'))
const ReceiptListPage = lazy(() => import('./pages/receipts/ReceiptListPage'))
const ReceiptDetailPage = lazy(() => import('./pages/receipts/ReceiptDetailPage'))
const ReceiptCreatePage = lazy(() => import('./pages/receipts/ReceiptCreatePage'))
const PartnerListPage = lazy(() => import('./pages/partners/PartnerListPage'))
const PartnerFormPage = lazy(() => import('./pages/partners/PartnerFormPage'))
const ProductListPage = lazy(() => import('./pages/products/ProductListPage'))
const ProductFormPage = lazy(() => import('./pages/products/ProductFormPage'))
const CompanyPage = lazy(() => import('./pages/CompanyPage'))
const AuditLogPage = lazy(() => import('./pages/AuditLogPage'))
const NavSubmissionsPage = lazy(() => import('./pages/NavSubmissionsPage'))
const UserListPage = lazy(() => import('./pages/users/UserListPage'))
const UserDetailPage = lazy(() => import('./pages/users/UserDetailPage'))
const GroupListPage = lazy(() => import('./pages/groups/GroupListPage'))
const GroupDetailPage = lazy(() => import('./pages/groups/GroupDetailPage'))
const DocumentSeriesSettingsPage = lazy(() => import('./pages/settings/DocumentSeriesSettingsPage'))
const TranslationPage = lazy(() => import('./pages/settings/TranslationPage'))
const CustomFieldsPage = lazy(() => import('./pages/settings/CustomFieldsPage'))
const CompanyListPage = lazy(() => import('./pages/companies/CompanyListPage'))
const ApiTesterPage = lazy(() => import('./pages/settings/ApiTesterPage'))
const ModulesPage = lazy(() => import('./pages/settings/ModulesPage'))
const SalesGroupPage = lazy(() => import('./pages/settings/SalesGroupPage'))
const AdminSalesGroupPage = lazy(() => import('./pages/settings/AdminSalesGroupPage'))
const AssetListPage = lazy(() => import('./pages/assets/AssetListPage'))
const AssetFormPage = lazy(() => import('./pages/assets/AssetFormPage'))
const AssetTypePage = lazy(() => import('./pages/settings/AssetTypePage'))
const JobPositionPage = lazy(() => import('./pages/settings/JobPositionPage'))
const CountriesPage = lazy(() => import('./pages/settings/CountriesPage'))
const WikiPage = lazy(() => import('./pages/WikiPage'))
const ReportsPage = lazy(() => import('./pages/reports/ReportsPage'))
const EnyugtaSettingsPage = lazy(() => import('./pages/settings/EnyugtaSettingsPage'))
const EnyugtaReportsPage = lazy(() => import('./pages/EnyugtaReportsPage'))
const EnyugtaReportDetailPage = lazy(() => import('./pages/EnyugtaReportDetailPage'))

export default function App() {
  return (
    <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route
          path="/"
          element={
            <ProtectedRoute>
              <Layout />
            </ProtectedRoute>
          }
        >
          {/* Nyitólap, Wiki: public route (l. ROUTE_ACCESS) — nincs RequirePermission.
              invoices/*, receipts/*: a sidebar sem köt hozzájuk jogot (árva route-ok,
              l. docs/progress.md) — szándékosan szintén védelem nélkül maradnak. */}
          <Route index element={<DashboardPage />} />
          <Route path="wiki" element={<WikiPage />} />
          <Route path="invoices" element={<InvoiceListPage />} />
          <Route path="invoices/new" element={<InvoiceCreatePage />} />
          <Route path="invoices/:id" element={<InvoiceDetailPage />} />
          <Route path="receipts" element={<ReceiptListPage />} />
          <Route path="receipts/new" element={<ReceiptCreatePage />} />
          <Route path="receipts/:id" element={<ReceiptDetailPage />} />

          <Route path="documents" element={
            <RequirePermission access={ROUTE_ACCESS['/documents']}><DocumentListPage /></RequirePermission>
          } />
          <Route path="reports" element={
            <RequirePermission access={ROUTE_ACCESS['/reports']}><ReportsPage /></RequirePermission>
          } />
          <Route path="partners" element={
            <RequirePermission access={ROUTE_ACCESS['/partners']}><PartnerListPage /></RequirePermission>
          } />
          <Route path="partners/new" element={
            <RequirePermission access={ROUTE_ACCESS['/partners/new']}><PartnerFormPage /></RequirePermission>
          } />
          <Route path="partners/:id/edit" element={
            <RequirePermission access={ROUTE_ACCESS['/partners/:id/edit']}><PartnerFormPage /></RequirePermission>
          } />
          <Route path="products" element={
            <RequirePermission access={ROUTE_ACCESS['/products']}><ProductListPage /></RequirePermission>
          } />
          <Route path="products/new" element={
            <RequirePermission access={ROUTE_ACCESS['/products/new']}><ProductFormPage /></RequirePermission>
          } />
          <Route path="products/:id/edit" element={
            <RequirePermission access={ROUTE_ACCESS['/products/:id/edit']}><ProductFormPage /></RequirePermission>
          } />
          <Route path="company" element={
            <RequirePermission access={ROUTE_ACCESS['/company']}><CompanyPage /></RequirePermission>
          } />
          <Route path="audit-logs" element={
            <RequirePermission access={ROUTE_ACCESS['/audit-logs']}><AuditLogPage /></RequirePermission>
          } />
          <Route path="nav-submissions" element={
            <RequirePermission access={ROUTE_ACCESS['/nav-submissions']}><NavSubmissionsPage /></RequirePermission>
          } />
          <Route path="users" element={
            <RequirePermission access={ROUTE_ACCESS['/users']}><UserListPage /></RequirePermission>
          } />
          <Route path="users/:id" element={
            <RequirePermission access={ROUTE_ACCESS['/users/:id']}><UserDetailPage /></RequirePermission>
          } />
          <Route path="groups" element={
            <RequirePermission access={ROUTE_ACCESS['/groups']}><GroupListPage /></RequirePermission>
          } />
          <Route path="groups/:id" element={
            <RequirePermission access={ROUTE_ACCESS['/groups/:id']}><GroupDetailPage /></RequirePermission>
          } />
          <Route path="settings/document-series" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/document-series']}><DocumentSeriesSettingsPage /></RequirePermission>
          } />
          <Route path="settings/translations" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/translations']}><TranslationPage /></RequirePermission>
          } />
          <Route path="settings/custom-fields" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/custom-fields']}><CustomFieldsPage /></RequirePermission>
          } />
          <Route path="companies" element={
            <RequirePermission access={ROUTE_ACCESS['/companies']}><CompanyListPage /></RequirePermission>
          } />
          <Route path="settings/modules" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/modules']}><ModulesPage /></RequirePermission>
          } />
          <Route path="settings/sales-groups" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/sales-groups']}><SalesGroupPage /></RequirePermission>
          } />
          <Route path="settings/sales-groups/all" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/sales-groups/all']}><AdminSalesGroupPage /></RequirePermission>
          } />
          <Route path="assets" element={
            <RequirePermission access={ROUTE_ACCESS['/assets']}><AssetListPage /></RequirePermission>
          } />
          <Route path="assets/new" element={
            <RequirePermission access={ROUTE_ACCESS['/assets/new']}><AssetFormPage /></RequirePermission>
          } />
          <Route path="assets/:id/edit" element={
            <RequirePermission access={ROUTE_ACCESS['/assets/:id/edit']}><AssetFormPage /></RequirePermission>
          } />
          <Route path="settings/asset-types" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/asset-types']}><AssetTypePage /></RequirePermission>
          } />
          <Route path="settings/job-positions" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/job-positions']}><JobPositionPage /></RequirePermission>
          } />
          <Route path="settings/countries" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/countries']}><CountriesPage /></RequirePermission>
          } />
          <Route path="settings/api-tester" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/api-tester']}><ApiTesterPage /></RequirePermission>
          } />
          <Route path="settings/enyugta" element={
            <RequirePermission access={ROUTE_ACCESS['/settings/enyugta']}><EnyugtaSettingsPage /></RequirePermission>
          } />
          <Route path="enyugta/reports" element={
            <RequirePermission access={ROUTE_ACCESS['/enyugta/reports']}><EnyugtaReportsPage /></RequirePermission>
          } />
          <Route path="enyugta/reports/:id" element={
            <RequirePermission access={ROUTE_ACCESS['/enyugta/reports/:id']}><EnyugtaReportDetailPage /></RequirePermission>
          } />
        </Route>
      </Routes>
  )
}
