import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { receipts } from '../../api/receipts'
import { useAuth } from '../../contexts/AuthContext'

export default function ReceiptListPage() {
  const { can } = useAuth()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    receipts.list().then((res) => { setData(res.data); setLoading(false) })
  }, [])

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Nyugták</h1>
        {can('receipt.create') && <Link to="/receipts/new" className="btn btn-primary">+ Új nyugta</Link>}
      </div>
      {loading ? <p className="text-muted">Betöltés…</p> : (
        <table>
          <thead><tr><th>Száma</th><th>Kelt</th><th>Bruttó</th><th>Státusz</th></tr></thead>
          <tbody>
            {data?.data.map((r) => (
              <tr key={r.id}>
                <td><Link to={`/receipts/${r.id}`} className="table-link">{r.receipt_number}</Link></td>
                <td>{r.issue_date}</td>
                <td className="text-right">{Number(r.gross_total).toLocaleString('hu')} {r.currency}</td>
                <td>{r.status === 'storno' ? <span className="badge badge-inv-storno">Sztornó</span> : <span className="badge badge-inv-issued">Kiállítva</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
