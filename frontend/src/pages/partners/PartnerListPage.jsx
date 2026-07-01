import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { partners } from '../../api/partners'
import { useAuth } from '../../contexts/AuthContext'
import PerPageSelector from '../../components/PerPageSelector'

export default function PartnerListPage() {
  const { can } = useAuth()
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
        <h1 className="page-title">Partnerek</h1>
        {can('partner.create') && <Link to="/partners/new" className="btn btn-primary">+ Új partner</Link>}
      </div>
      <form className="search-row" onSubmit={(e) => { e.preventDefault(); load(search, perPage) }}>
        <input placeholder="Név vagy adószám…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="btn btn-secondary" type="submit">Keresés</button>
        <PerPageSelector value={perPage} onChange={handlePerPage} />
      </form>
      {loading ? <p className="text-muted">Betöltés…</p> : (
        <table>
          <thead><tr><th>Név</th><th>Adószám</th><th>Típus</th><th>Város</th><th>E-mail</th><th></th></tr></thead>
          <tbody>
            {data?.data.map((p) => (
              <tr key={p.id}>
                <td>{p.name}</td>
                <td>{p.tax_number ?? '—'}</td>
                <td>{p.type}</td>
                <td>{p.billing_city}</td>
                <td>{p.email ?? '—'}</td>
                <td className="flex">
                  {can('partner.edit') && <Link to={`/partners/${p.id}/edit`} className="btn btn-secondary btn-sm">Szerkesztés</Link>}
                  {can('partner.delete') && <button className="btn btn-danger btn-sm" onClick={() => handleDelete(p.id)}>Törlés</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && <p className="text-muted mt-4">Összesen: {data.meta?.total} db</p>}
    </div>
  )
}
