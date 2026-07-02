import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { partners } from '../../api/partners'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import PerPageSelector from '../../components/PerPageSelector'

export default function PartnerListPage() {
  const { can } = useAuth()
  const { t } = useTranslation()
  const [data, setData]       = useState(null)
  const [search, setSearch]   = useState('')
  const [loading, setLoading] = useState(true)
  const [perPage, setPerPage] = useState(20)

  async function load(s, pp) {
    setLoading(true)
    try {
      const res = await partners.list({ search: s || undefined, per_page: pp })
      setData(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load('', 20) }, [])

  async function handleDelete(id) {
    if (!confirm('Biztosan törli?')) return
    await partners.destroy(id)
    load(search, perPage)
  }

  function handlePerPage(value) {
    setPerPage(value)
    load(search, value)
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">{t('partner.title')}</h1>
        {can('partner.create') && <Link to="/partners/new" className="btn btn-primary">+ {t('partner.new')}</Link>}
      </div>
      <form className="search-row" onSubmit={(e) => { e.preventDefault(); load(search, perPage) }}>
        <input placeholder="Név vagy adószám…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="btn btn-secondary" type="submit">{t('common.search')}</button>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
      </form>
      {loading ? <p className="text-muted">{t('common.loading')}</p> : (
        <table>
          <thead><tr><th>{t('common.name')}</th><th>{t('partner.tax_number')}</th><th>Típus</th><th>{t('partner.city')}</th><th>{t('common.email')}</th><th></th></tr></thead>
          <tbody>
            {data?.data.map((p) => (
              <tr key={p.id}>
                <td>{p.name}</td>
                <td>{p.tax_number ?? '—'}</td>
                <td>{p.type}</td>
                <td>{p.billing_city}</td>
                <td>{p.email ?? '—'}</td>
                <td className="flex">
                  {can('partner.edit') && <Link to={`/partners/${p.id}/edit`} className="btn btn-secondary btn-sm">{t('common.edit')}</Link>}
                  {can('partner.delete') && <button className="btn btn-danger btn-sm" onClick={() => handleDelete(p.id)}>{t('common.delete')}</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && <p className="text-muted mt-4">{t('common.total')}: {data.meta?.total} {t('common.pieces')}</p>}
    </div>
  )
}
