import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { RotateCcw } from 'lucide-react'
import { invoices } from '../../api/invoices'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { PaymentStatusBadge, InvoiceStatusBadge } from '../../components/StatusBadge'
import PerPageSelector from '../../components/PerPageSelector'
import Pagination from '../../components/Pagination'

export default function InvoiceListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const [data, setData]       = useState(null)
  const [search, setSearch]   = useState('')
  const [loading, setLoading] = useState(true)
  const [perPage, setPerPage] = useState(20)
  const [page, setPage]       = useState(1)

  async function load(s, pp, pg) {
    setLoading(true)
    try {
      const res = await invoices.list({ search: s || undefined, per_page: pp, page: pg })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load('', 20, 1) }, [])

  function handleSearch(e) {
    e.preventDefault()
    setPage(1)
    load(search, perPage, 1)
  }

  function handlePerPage(value) {
    setPerPage(value); setPage(1)
    load(search, value, 1)
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('invoice.title')}</h1>
        {can('invoice.create') && (
          <Link to="/invoices/new" className="btn btn-primary">{t('invoice.new')}</Link>
        )}
      </div>
      <form className="search-row" onSubmit={handleSearch}>
        <input placeholder="Számlaszám vagy partner neve…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="btn btn-secondary" type="submit">{t('common.search')}</button>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
      </form>
      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <table>
          <thead>
            <tr>
              <th>{t('invoice.number_col')}</th><th>{t('invoice.partner_col')}</th><th>{t('invoice.issue_date')}</th><th>{t('invoice.gross_col')}</th><th>{t('common.currency')}</th><th>Státusz</th><th>Fizetés</th>
            </tr>
          </thead>
          <tbody>
            {data?.data.map((inv) => (
              <tr key={inv.id}>
                <td><Link to={`/invoices/${inv.id}`} className="table-link">{inv.invoice_number}</Link></td>
                <td>{inv.partner?.name ?? '—'}</td>
                <td>{inv.issue_date}</td>
                <td className="text-right">{Number(inv.gross_total).toLocaleString('hu')} {inv.currency}</td>
                <td>{inv.currency}</td>
                <td style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <InvoiceStatusBadge status={inv.status} />
                  {inv.has_storno && (
                    <RotateCcw
                      size={13}
                      color="var(--color-accent-text)"
                      aria-label={t('invoice.has_storno_tooltip')}
                      title={t('invoice.has_storno_tooltip')}
                    />
                  )}
                </td>
                <td><PaymentStatusBadge status={inv.payment_status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total} {t('common.pieces')}</p>}
      <Pagination meta={data?.meta} onChange={(p) => { setPage(p); load(search, perPage, p) }} />
    </div>
  )
}
