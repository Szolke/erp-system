import { useSearchParams } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import InvoicesReportTab from './InvoicesReportTab'
import ProductsReportTab from './ProductsReportTab'
import ReceivablesAgingReportTab from './ReceivablesAgingReportTab'
import VatSummaryReportTab from './VatSummaryReportTab'

const TAB_COMPONENTS = {
  invoices: InvoicesReportTab,
  products: ProductsReportTab,
  aging: ReceivablesAgingReportTab,
  vat: VatSummaryReportTab,
}

/**
 * A négy riport-fül közös kerete. Az aktív fület a `tab` query-paraméter
 * tárolja — minden más szűrő-paramétert (from/to/date_basis/stb.) maga a
 * fül-komponens olvas/ír a saját useUrlFilters()-hívásán keresztül.
 *
 * A cím + fülek + export gomb sora MOST a ReportFilterCard (l. components/
 * reports/) 1. sorában él, minden fül-komponens ezt rendereli a saját
 * tartalma fölött — ezért itt nincs külön page-header/tab-bar, csak az
 * aktív komponens kiválasztása és az activeTab/onTabChange átadása.
 */
export default function ReportsPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()

  if (!can('report.view')) {
    return <p className="text-muted">{t('common.no_permission')}</p>
  }

  const activeTab = TAB_COMPONENTS[searchParams.get('tab')] ? searchParams.get('tab') : 'invoices'

  function switchTab(key) {
    const next = new URLSearchParams()
    next.set('tab', key)
    setSearchParams(next, { replace: true })
  }

  const ActiveComponent = TAB_COMPONENTS[activeTab]

  return <ActiveComponent activeTab={activeTab} onTabChange={switchTab} />
}
