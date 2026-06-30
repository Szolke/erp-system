import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { products } from '../../api/products'
import { useAuth } from '../../contexts/AuthContext'

export default function ProductListPage() {
  const { can } = useAuth()
  const [data, setData] = useState(null)
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)

  async function load(s) {
    setLoading(true)
    const res = await products.list({ search: s || undefined })
    setData(res.data)
    setLoading(false)
  }

  useEffect(() => { load('') }, [])

  async function handleDelete(id) {
    if (!confirm('Biztosan törli?')) return
    await products.destroy(id)
    load(search)
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Termékek / Szolgáltatások</h1>
        {can('product.create') && <Link to="/products/new" className="btn btn-primary">+ Új tétel</Link>}
      </div>
      <form className="search-row" onSubmit={(e) => { e.preventDefault(); load(search) }}>
        <input placeholder="Név vagy cikkszám…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="btn btn-secondary" type="submit">Keresés</button>
      </form>
      {loading ? <p className="text-muted">Betöltés…</p> : (
        <table>
          <thead><tr><th>Cikkszám</th><th>Név</th><th>Me.</th><th>Alapár</th><th>ÁFA</th><th>Típus</th><th></th></tr></thead>
          <tbody>
            {data?.data.map((p) => (
              <tr key={p.id}>
                <td>{p.sku}</td>
                <td>{p.name}</td>
                <td>{p.unit}</td>
                <td className="text-right">{Number(p.base_price).toLocaleString('hu')} {p.base_currency}</td>
                <td>{p.vat_rate?.name}</td>
                <td>{p.type === 'service' ? 'Szolgáltatás' : 'Termék'}</td>
                <td className="flex">
                  {can('product.edit') && <Link to={`/products/${p.id}/edit`} className="btn btn-secondary btn-sm">Szerkesztés</Link>}
                  {can('product.delete') && <button className="btn btn-danger btn-sm" onClick={() => handleDelete(p.id)}>Törlés</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
