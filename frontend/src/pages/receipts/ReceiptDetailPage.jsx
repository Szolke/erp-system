import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { receipts as receiptApi } from '../../api/receipts'
import { useAuth } from '../../contexts/AuthContext'

export default function ReceiptDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { can } = useAuth()
  const [receipt, setReceipt] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    receiptApi.get(id).then((res) => { setReceipt(res.data.data); setLoading(false) })
  }, [id])

  async function handleCancel() {
    if (!confirm('Biztosan sztornózza a nyugtát?')) return
    await receiptApi.cancel(id)
    navigate('/receipts')
  }

  if (loading) return <p className="text-muted">Betöltés…</p>

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{receipt.receipt_number}</h1>
        <div className="flex">
          {can('receipt.cancel') && receipt.status === 'issued' && (
            <button className="btn btn-danger" onClick={handleCancel}>Sztornó</button>
          )}
          <Link to="/receipts" className="btn btn-secondary">← Vissza</Link>
        </div>
      </div>
      <div className="card">
        <div className="detail-grid">
          <div className="detail-row"><span className="detail-label">Kelt</span><span className="detail-value">{receipt.issue_date}</span></div>
          <div className="detail-row"><span className="detail-label">Fizetési mód</span><span className="detail-value">{receipt.payment_method?.name}</span></div>
        </div>
        <table className="items-table">
          <thead><tr><th>Megnevezés</th><th>Mennyiség</th><th>Egységár</th><th>Bruttó</th></tr></thead>
          <tbody>
            {receipt.items?.map((item) => (
              <tr key={item.id}>
                <td>{item.description}</td>
                <td className="text-right">{item.quantity}</td>
                <td className="text-right">{Number(item.unit_price).toLocaleString('hu')}</td>
                <td className="text-right">{Number(item.gross_amount).toLocaleString('hu')}</td>
              </tr>
            ))}
            <tr className="total-row">
              <td colSpan={3}>Összesen</td>
              <td className="text-right">{Number(receipt.gross_total).toLocaleString('hu')} {receipt.currency}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  )
}
