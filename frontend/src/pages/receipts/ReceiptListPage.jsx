import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { receipts } from '../../api/receipts'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import PerPageSelector from '../../components/PerPageSelector'

export default function ReceiptListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [perPage, setPerPage] = useState(20)

  async function load(pp) {
    setLoading(true)
    try {
      const res = await receipts.list({ per_page: pp })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load(20) }, [])

  function handlePerPage(value) {
    setPerPage(value)
    load(value)
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('receipt.title')}</h1>
        {can('receipt.create') && <Link to="/receipts/new" className="btn btn-primary">+ {t('receipt.new')}</Link>}
      </div>
      <div className="search-row">
        <PerPageSelector value={perPage} onChange={handlePerPage} />
      </div>
      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <table>
          <thead><tr><th>{t('receipt.number_col')}</th><th>{t('receipt.issued_col')}</th><th>{t('invoice.gross_col')}</th><th>Státusz</th></tr></thead>
          <tbody>
            {data?.data.map((r) => (
              <tr key={r.id}>
                <td><Link to={`/receipts/${r.id}`} className="table-link">{r.receipt_number}</Link></td>
                <td>{r.issue_date}</td>
                <td className="text-right">{Number(r.gross_total).toLocaleString('hu')} {r.currency}</td>
                <td>{r.status === 'storno' ? <span className="badge badge-inv-storno">{t('common.storno')}</span> : <span className="badge badge-inv-issued">Kiállítva</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total} {t('common.pieces')}</p>}
    </div>
  )
}
