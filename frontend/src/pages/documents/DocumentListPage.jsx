import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { documents } from '../../api/documents'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { DocumentTypeBadge, InvoiceStatusBadge, PaymentStatusBadge } from '../../components/StatusBadge'
import PerPageSelector from '../../components/PerPageSelector'

export default function DocumentListPage() {
  const { can }  = useAuth()
  const { t }    = useTranslation()

  const [data, setData]                     = useState(null)
  const [loading, setLoading]               = useState(true)
  const [type, setType]                     = useState('')
  const [search, setSearch]                 = useState('')
  const [dateFrom, setDateFrom]             = useState('')
  const [dateTo, setDateTo]                 = useState('')
  const [currency, setCurrency]             = useState('')
  const [paymentStatus, setPaymentStatus]   = useState('')
  const [perPage, setPerPage]               = useState(20)

  const TYPE_FILTERS = [
    { value: '',               label: t('document.type_all') },
    { value: 'invoice',        label: t('document.type_invoice'),  needsPerm: 'invoice.view' },
    { value: 'invoice_storno', label: t('document.type_inv_st'),   needsPerm: 'invoice.view' },
    { value: 'receipt',        label: t('document.type_receipt'),  needsPerm: 'receipt.view' },
    { value: 'receipt_storno', label: t('document.type_rec_st'),   needsPerm: 'receipt.view' },
  ]

  async function load({ t: tp, s, df, dt, cur, ps, pp } = {}) {
    const params = {
      type:           (tp  ?? type)          || undefined,
      search:         (s   ?? search)        || undefined,
      date_from:      (df  ?? dateFrom)      || undefined,
      date_to:        (dt  ?? dateTo)        || undefined,
      currency:       (cur ?? currency)      || undefined,
      payment_status: (ps  ?? paymentStatus) || undefined,
      per_page:       pp ?? perPage,
    }
    setLoading(true)
    try {
      const res = await documents.list(params)
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  function handleTypeChange(value) {
    setType(value); setSearch('')
    const clearedPs = (value === 'receipt' || value === 'receipt_storno') ? '' : paymentStatus
    if (clearedPs !== paymentStatus) setPaymentStatus('')
    load({ t: value, s: '', ps: clearedPs })
  }
  function clearSearch()           { setSearch(''); load({ s: '' }) }
  function handleDateFrom(value)   { setDateFrom(value); load({ df: value }) }
  function handleDateTo(value)     { setDateTo(value); load({ dt: value }) }
  function clearDates()            { setDateFrom(''); setDateTo(''); load({ df: '', dt: '' }) }
  function handleCurrency(value)   { setCurrency(value); load({ cur: value }) }
  function handlePaymentStatus(v)  { setPaymentStatus(v); load({ ps: v }) }
  function handlePerPage(value)    { setPerPage(value); load({ pp: value }) }
  function handleSearch(e)         { e.preventDefault(); load() }

  function docLink(doc) {
    return doc.model_type === 'invoice' ? `/invoices/${doc.id}` : `/receipts/${doc.id}`
  }

  const visibleFilters = TYPE_FILTERS.filter((f) => !f.needsPerm || can(f.needsPerm))
  const hasDateFilter  = dateFrom || dateTo

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('document.title')}</h1>
        <div className="flex">
          {can('invoice.create') && <Link to="/invoices/new" className="btn btn-primary">{t('document.new_invoice')}</Link>}
          {can('receipt.create') && <Link to="/receipts/new" className="btn btn-secondary">{t('document.new_receipt')}</Link>}
        </div>
      </div>

      <div className="type-filter">
        {visibleFilters.map((f) => (
          <button key={f.value} className={type === f.value ? 'active' : ''} onClick={() => handleTypeChange(f.value)}>
            {f.label}
          </button>
        ))}
      </div>

      <div className="filter-row">
        <label className="filter-select-wrap">
          <span>{t('common.currency')}</span>
          <select value={currency} onChange={(e) => handleCurrency(e.target.value)}>
            <option value="">{t('document.curr_all')}</option>
            <option value="HUF">HUF</option>
            <option value="EUR">EUR</option>
            <option value="USD">USD</option>
          </select>
        </label>
        {type !== 'receipt' && type !== 'receipt_storno' && (
          <label className="filter-select-wrap">
            <span>{t('document.pay_status')}</span>
            <select value={paymentStatus} onChange={(e) => handlePaymentStatus(e.target.value)}>
              <option value="">{t('document.pay_all')}</option>
              <option value="open">{t('document.pay_open')}</option>
              <option value="partial">{t('document.pay_partial')}</option>
              <option value="paid">{t('document.pay_paid')}</option>
            </select>
          </label>
        )}
      </div>

      <form className="search-row" onSubmit={handleSearch}>
        <div className="search-input-wrap">
          <input placeholder={t('document.search_ph')} value={search} onChange={(e) => setSearch(e.target.value)} />
          {search && <button type="button" className="search-clear" onClick={clearSearch}>✕</button>}
        </div>
        <button className="btn btn-secondary" type="submit">{t('common.search')}</button>
        <span className="date-range">
          <label>{t('document.date_from')}</label>
          <input type="date" value={dateFrom} onChange={(e) => handleDateFrom(e.target.value)} />
          <label>{t('document.date_to')}</label>
          <input type="date" value={dateTo} onChange={(e) => handleDateTo(e.target.value)} />
          {hasDateFilter && <button type="button" className="btn-link date-clear" onClick={clearDates}>✕</button>}
        </span>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
      </form>

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>{t('document.number')}</th>
              <th>{t('document.type_col')}</th>
              <th>{t('document.partner')}</th>
              <th>{t('document.issued_at')}</th>
              <th className="text-right">{t('document.gross')}</th>
              <th>{t('common.currency')}</th>
              <th>{t('document.status_col')}</th>
              <th>{t('document.payment_col')}</th>
            </tr>
          </thead>
          <tbody>
            {data?.data.map((doc) => (
              <tr key={`${doc.model_type}-${doc.id}`}>
                <td><Link to={docLink(doc)} className="table-link">{doc.document_number}</Link></td>
                <td><DocumentTypeBadge type={doc.document_type} /></td>
                <td>{doc.partner_name ?? '—'}</td>
                <td>{doc.issue_date}</td>
                <td className="text-right">{Number(doc.gross_total).toLocaleString('hu')}</td>
                <td>{doc.currency}</td>
                <td><InvoiceStatusBadge status={doc.status} /></td>
                <td>{doc.payment_status ? <PaymentStatusBadge status={doc.payment_status} /> : <span className="text-muted">—</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {data && <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total} {t('common.pieces')}</p>}
    </div>
  )
}
