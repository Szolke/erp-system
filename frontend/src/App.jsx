import { lazy } from 'react'
import { Routes, Route } from 'react-router-dom'
import Layout from './components/Layout'
import ProtectedRoute from './components/ProtectedRoute'
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
          <Route index element={<DashboardPage />} />
          <Route path="documents" element={<DocumentListPage />} />
          <Route path="invoices" element={<InvoiceListPage />} />
          <Route path="invoices/new" element={<InvoiceCreatePage />} />
          <Route path="invoices/:id" element={<InvoiceDetailPage />} />
          <Route path="receipts" element={<ReceiptListPage />} />
          <Route path="receipts/new" element={<ReceiptCreatePage />} />
          <Route path="receipts/:id" element={<ReceiptDetailPage />} />
          <Route path="partners" element={<PartnerListPage />} />
          <Route path="partners/new" element={<PartnerFormPage />} />
          <Route path="partners/:id/edit" element={<PartnerFormPage />} />
          <Route path="products" element={<ProductListPage />} />
          <Route path="products/new" element={<ProductFormPage />} />
          <Route path="products/:id/edit" element={<ProductFormPage />} />
          <Route path="company" element={<CompanyPage />} />
          <Route path="audit-logs" element={<AuditLogPage />} />
          <Route path="nav-submissions" element={<NavSubmissionsPage />} />
          <Route path="users" element={<UserListPage />} />
          <Route path="users/:id" element={<UserDetailPage />} />
          <Route path="groups" element={<GroupListPage />} />
          <Route path="groups/:id" element={<GroupDetailPage />} />
          <Route path="settings/document-series" element={<DocumentSeriesSettingsPage />} />
          <Route path="settings/translations" element={<TranslationPage />} />
          <Route path="settings/custom-fields" element={<CustomFieldsPage />} />
          <Route path="companies" element={<CompanyListPage />} />
          <Route path="settings/modules" element={<ModulesPage />} />
          <Route path="settings/sales-groups" element={<SalesGroupPage />} />
          <Route path="assets" element={<AssetListPage />} />
          <Route path="assets/new" element={<AssetFormPage />} />
          <Route path="assets/:id/edit" element={<AssetFormPage />} />
          <Route path="settings/asset-types" element={<AssetTypePage />} />
          <Route path="settings/job-positions" element={<JobPositionPage />} />
          <Route path="settings/countries" element={<CountriesPage />} />
          <Route path="settings/api-tester" element={<ApiTesterPage />} />
          <Route path="wiki" element={<WikiPage />} />
          <Route path="reports" element={<ReportsPage />} />
          <Route path="settings/enyugta" element={<EnyugtaSettingsPage />} />
          <Route path="enyugta/reports" element={<EnyugtaReportsPage />} />
          <Route path="enyugta/reports/:id" element={<EnyugtaReportDetailPage />} />
        </Route>
      </Routes>
  )
}
