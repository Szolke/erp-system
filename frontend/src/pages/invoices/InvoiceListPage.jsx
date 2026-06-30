import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { invoices } from '../../api/invoices'
import { useAuth } from '../../contexts/AuthContext'
import { PaymentStatusBadge, InvoiceStatusBadge } from '../../components/StatusBadge'

export default function InvoiceListPage() {
  const { can } = useAuth()
  const [data, setData] = useState(null)
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)

  async function load(s) {
    setLoading(true)
    try {
      const res = await invoices.list({ search: s || undefined })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load('') }, [])

  function handleSearch(e) {
    e.preventDefault()
    load(search)
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Számlák</h1>
        {can('invoice.create') && (
          <Link to="/invoices/new" className="btn btn-primary">+ Új számla</Link>
        )}
      </div>
      <form className="search-row" onSubmit={handleSearch}>
        <input placeholder="Számlaszám vagy partner neve…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="btn btn-secondary" type="submit">Keresés</button>
      </form>
      {loading ? <p className="text-muted">Betöltés…</p> : (
        <table>
          <thead>
            <tr>
              <th>Számlaszám</th><th>Partner</th><th>Kelt</th><th>Bruttó</th><th>Deviza</th><th>Státusz</th><th>Fizetés</th>
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
                <td><InvoiceStatusBadge status={inv.status} /></td>
                <td><PaymentStatusBadge status={inv.payment_status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && <p className="text-muted mt-4">Összesen: {data.meta?.total} db</p>}
    </div>
  )
}
