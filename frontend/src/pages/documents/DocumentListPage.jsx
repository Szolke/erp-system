import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { documents } from '../../api/documents'
import { useAuth } from '../../contexts/AuthContext'
import { DocumentTypeBadge, InvoiceStatusBadge, PaymentStatusBadge } from '../../components/StatusBadge'
import PerPageSelector from '../../components/PerPageSelector'

const TYPE_FILTERS = [
  { value: '',               label: 'Összes' },
  { value: 'invoice',        label: 'Számla',          needsPerm: 'invoice.view' },
  { value: 'invoice_storno', label: 'Sztornó számla',  needsPerm: 'invoice.view' },
  { value: 'receipt',        label: 'Nyugta',          needsPerm: 'receipt.view' },
  { value: 'receipt_storno', label: 'Sztornó nyugta',  needsPerm: 'receipt.view' },
]

export default function DocumentListPage() {
  const { can } = useAuth()
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [type, setType]       = useState('')
  const [search, setSearch]   = useState('')
  const [perPage, setPerPage] = useState(20)

  async function load(t, s, pp) {
    setLoading(true)
    try {
      const res = await documents.list({
        type:     t || undefined,
        search:   s || undefined,
        per_page: pp,
      })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load('', '', 20) }, [])

  function handleTypeChange(value) {
    setType(value)
    load(value, search, perPage)
  }

  function handleSearch(e) {
    e.preventDefault()
    load(type, search, perPage)
  }

  function handlePerPage(value) {
    setPerPage(value)
    load(type, search, value)
  }

  function docLink(doc) {
    return doc.model_type === 'invoice'
      ? `/invoices/${doc.id}`
      : `/receipts/${doc.id}`
  }

  const visibleFilters = TYPE_FILTERS.filter(
    (f) => !f.needsPerm || can(f.needsPerm)
  )

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Bizonylatok</h1>
        <div className="flex">
          {can('invoice.create') && (
            <Link to="/invoices/new" className="btn btn-primary">+ Új számla</Link>
          )}
          {can('receipt.create') && (
            <Link to="/receipts/new" className="btn btn-secondary">+ Új nyugta</Link>
          )}
        </div>
      </div>

      <div className="type-filter">
        {visibleFilters.map((f) => (
          <button
            key={f.value}
            className={type === f.value ? 'active' : ''}
            onClick={() => handleTypeChange(f.value)}
          >
            {f.label}
          </button>
        ))}
      </div>

      <form className="search-row" onSubmit={handleSearch}>
        <input
          placeholder="Bizonylat száma vagy partner neve…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <button className="btn btn-secondary" type="submit">Keresés</button>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
      </form>

      {loading ? (
        <p className="text-muted">Betöltés…</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Bizonylat száma</th>
              <th>Típus</th>
              <th>Partner</th>
              <th>Kelt</th>
              <th className="text-right">Bruttó</th>
              <th>Deviza</th>
              <th>Állapot</th>
              <th>Fizetés</th>
            </tr>
          </thead>
          <tbody>
            {data?.data.map((doc) => (
              <tr key={`${doc.model_type}-${doc.id}`}>
                <td>
                  <Link to={docLink(doc)} className="table-link">
                    {doc.document_number}
                  </Link>
                </td>
                <td><DocumentTypeBadge type={doc.document_type} /></td>
                <td>{doc.partner_name ?? '—'}</td>
                <td>{doc.issue_date}</td>
                <td className="text-right">
                  {Number(doc.gross_total).toLocaleString('hu')}
                </td>
                <td>{doc.currency}</td>
                <td><InvoiceStatusBadge status={doc.status} /></td>
                <td>
                  {doc.payment_status
                    ? <PaymentStatusBadge status={doc.payment_status} />
                    : <span className="text-muted">—</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {data && (
        <p className="text-muted mt-4">Összesen: {data.meta?.total} db</p>
      )}
    </div>
  )
}
