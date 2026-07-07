import { Routes, Route, Navigate } from 'react-router-dom'
import Layout from './components/Layout'
import ProtectedRoute from './components/ProtectedRoute'
import LoginPage from './pages/LoginPage'
import InvoiceListPage from './pages/invoices/InvoiceListPage'
import InvoiceDetailPage from './pages/invoices/InvoiceDetailPage'
import InvoiceCreatePage from './pages/invoices/InvoiceCreatePage'
import ReceiptListPage from './pages/receipts/ReceiptListPage'
import ReceiptDetailPage from './pages/receipts/ReceiptDetailPage'
import ReceiptCreatePage from './pages/receipts/ReceiptCreatePage'
import PartnerListPage from './pages/partners/PartnerListPage'
import PartnerFormPage from './pages/partners/PartnerFormPage'
import ProductListPage from './pages/products/ProductListPage'
import ProductFormPage from './pages/products/ProductFormPage'
import CompanyPage from './pages/CompanyPage'
import AuditLogPage from './pages/AuditLogPage'
import UserListPage from './pages/users/UserListPage'
import UserDetailPage from './pages/users/UserDetailPage'
import GroupListPage from './pages/groups/GroupListPage'
import GroupDetailPage from './pages/groups/GroupDetailPage'
import DocumentSeriesSettingsPage from './pages/settings/DocumentSeriesSettingsPage'
import DocumentListPage from './pages/documents/DocumentListPage'
import TranslationPage from './pages/settings/TranslationPage'
import CustomFieldsPage from './pages/settings/CustomFieldsPage'
import CompanyListPage from './pages/companies/CompanyListPage'
import ApiTesterPage from './pages/settings/ApiTesterPage'
import WikiPage from './pages/WikiPage'

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
          <Route index element={<Navigate to="/documents" replace />} />
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
          <Route path="users" element={<UserListPage />} />
          <Route path="users/:id" element={<UserDetailPage />} />
          <Route path="groups" element={<GroupListPage />} />
          <Route path="groups/:id" element={<GroupDetailPage />} />
          <Route path="settings/document-series" element={<DocumentSeriesSettingsPage />} />
          <Route path="settings/translations" element={<TranslationPage />} />
          <Route path="settings/custom-fields" element={<CustomFieldsPage />} />
          <Route path="companies" element={<CompanyListPage />} />
          <Route path="settings/api-tester" element={<ApiTesterPage />} />
          <Route path="wiki" element={<WikiPage />} />
        </Route>
      </Routes>
  )
}
