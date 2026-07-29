import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from '../../contexts/TranslationContext'
import { adminSalesGroups as adminSgApi } from '../../api/adminSalesGroups'

/**
 * Cégek közötti értékesítő csoport nézet (superadmin) — CSAK OLVASÁS.
 *
 * A `GET /api/admin/sales-groups` egyetlen, LAPOZATLAN listát ad vissza, cégre
 * rendezve. A cégenkénti csoportosítást mégis itt, kliensoldalon végezzük el
 * (nem a szerver sorrendjére hagyatkozva), hogy a nézet akkor is helyes
 * maradjon, ha a végpont rendezése egyszer megváltozna.
 *
 * Szerkesztő elem szándékosan NINCS: a módosítás továbbra is cégre scope-olva,
 * az Értékesítő csoportok oldalon történik — l. `AdminSalesGroupController`
 * osztály-kommentje a backenden.
 */

/** Lapos csoport-lista → cégenkénti tömbök, cégnév szerint rendezve. */
function groupByCompany(groups) {
  const byCompany = new Map()

  for (const group of groups) {
    // A `company` elvileg mindig kitöltött (a csoport nem létezhet cég nélkül),
    // de a végpont `?->` operátorral építi, ezért defenzíven kezeljük: a
    // besorolhatatlan sorok se tűnjenek el némán a nézetből.
    const id   = group.company?.id ?? null
    const name = group.company?.name ?? '(ismeretlen cég)'

    if (!byCompany.has(id)) byCompany.set(id, { id, name, groups: [] })
    byCompany.get(id).groups.push(group)
  }

  return [...byCompany.values()].sort((a, b) => a.name.localeCompare(b.name, 'hu'))
}

export default function AdminSalesGroupPage() {
  const { t } = useTranslation()

  const [companies, setCompanies] = useState([])
  const [loading, setLoading]     = useState(true)
  const [error, setError]         = useState('')

  useEffect(() => {
    let cancelled = false

    adminSgApi.list()
      .then((res) => {
        if (cancelled) return
        setCompanies(groupByCompany(res.data.data ?? []))
      })
      .catch((err) => {
        if (cancelled) return
        setError(err.response?.data?.message ?? t('common.error'))
      })
      .finally(() => { if (!cancelled) setLoading(false) })

    return () => { cancelled = true }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const groupCount = companies.reduce((sum, c) => sum + c.groups.length, 0)

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Értékesítő csoportok — összes cég</h1>
      </div>

      <p className="text-muted" style={{ fontSize: 13, marginBottom: 16 }}>
        Áttekintő nézet minden cég értékesítő csoportjairól és tagjairól.{' '}
        <strong>Csak megtekintés</strong> — a szerkesztés cégenként, az{' '}
        <Link to="/settings/sales-groups" className="table-link">Értékesítő csoportok</Link>{' '}
        oldalon történik, az aktuálisan kiválasztott cégre.
      </p>

      {error && <div className="alert-error mb-4">{error}</div>}

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : !error && companies.length === 0 ? (
        <p className="text-muted">Egyetlen cégben sincs értékesítő csoport.</p>
      ) : (
        companies.map((company) => (
          <div className="card" key={company.id ?? company.name}>
            <div style={{
              display: 'flex', alignItems: 'baseline', justifyContent: 'space-between',
              gap: 12, marginBottom: 14,
            }}>
              <h2 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>{company.name}</h2>
              <span className="text-muted" style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
                {company.groups.length} csoport
              </span>
            </div>

            <table>
              <thead>
                <tr>
                  <th style={{ width: '30%' }}>Csoport</th>
                  <th>Tagok</th>
                </tr>
              </thead>
              <tbody>
                {company.groups.map((group) => (
                  <tr key={group.id}>
                    <td style={{ verticalAlign: 'top' }}>
                      <code style={{ fontSize: 13 }}>{group.display_name}</code>
                      {/* A `name` a prefix nélküli, nyers név — csak akkor
                          mutatjuk külön, ha eltér a megjelenítőnévtől
                          (prefix nélküli cégnél a kettő azonos lenne). */}
                      {group.display_name !== group.name && (
                        <div className="text-muted" style={{ fontSize: 12, marginTop: 2 }}>
                          {group.name}
                        </div>
                      )}
                    </td>
                    <td>
                      {group.users.length === 0 ? (
                        <span className="text-muted" style={{ fontSize: 13 }}>Nincs tag.</span>
                      ) : (
                        <div style={{
                          display: 'grid',
                          gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))',
                          gap: '2px 20px',
                        }}>
                          {group.users.map((u) => (
                            <span key={u.id} style={{ fontSize: 13 }}>
                              {u.name}{' '}
                              <span className="text-muted" style={{ fontSize: 12 }}>({u.email})</span>
                            </span>
                          ))}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))
      )}

      {!loading && !error && companies.length > 0 && (
        <p className="text-muted mt-4">
          {t('common.total')}: {groupCount} csoport, {companies.length} cégben
        </p>
      )}
    </div>
  )
}
