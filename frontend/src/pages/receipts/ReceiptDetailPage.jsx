import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { receipts as receiptApi } from '../../api/receipts'
import client from '../../api/client'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'

export default function ReceiptDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { can } = useAuth()
  const { t } = useTranslation()
  const [receipt, setReceipt] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    receiptApi.get(id).then((res) => { setReceipt(res.data.data); setLoading(false) })
  }, [id])

  async function downloadPdf() {
    const res = await client.get(`/api/receipts/${receipt.id}/pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `${receipt.receipt_number}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  }

  async function regeneratePdf() {
    if (!confirm(t('receipt.regenerate_pdf_confirm'))) return
    await client.post(`/api/receipts/${receipt.id}/regenerate-pdf`)
  }

  async function handleCancel() {
    if (!confirm(t('receipt.storno_confirm'))) return
    await receiptApi.cancel(id)
    navigate('/receipts')
  }

  if (loading) return <p className="text-muted">{t('common.loading')}</p>

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{receipt.receipt_number}</h1>
        <div className="flex">
          {can('receipt.cancel') && receipt.status === 'issued' && (
            <button className="btn btn-danger" onClick={handleCancel}>{t('common.storno')}</button>
          )}
          <button className="btn btn-secondary" onClick={downloadPdf}>PDF</button>
          {can('receipt.regenerate_pdf') && (
            <button className="btn btn-secondary" onClick={regeneratePdf}>{t('receipt.regenerate_pdf')}</button>
          )}
          <Link to="/documents" className="btn btn-secondary">{t('common.back')}</Link>
        </div>
      </div>
      <div className="card">
        <div className="detail-grid">
          <div className="detail-row"><span className="detail-label">{t('receipt.issued_col')}</span><span className="detail-value">{receipt.issue_date}</span></div>
          <div className="detail-row"><span className="detail-label">{t('invoice.pay_method')}</span><span className="detail-value">{receipt.payment_method?.name}</span></div>
        </div>
        <table className="items-table">
          <thead><tr><th>{t('invoice.description')}</th><th>{t('invoice.quantity')}</th><th>{t('invoice.unit_price')}</th><th>{t('invoice.gross')}</th></tr></thead>
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
              <td colSpan={3}>{t('common.total')}</td>
              <td className="text-right">{Number(receipt.gross_total).toLocaleString('hu')} {receipt.currency}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  )
}
