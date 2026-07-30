import { Fragment, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { adminSalesGroups as adminSgApi } from '../../api/adminSalesGroups'
import { companies as companiesApi } from '../../api/company'
import SalesGroupForm from '../../components/SalesGroupForm'
import { describeCrossCompanyError, fieldError } from '../../utils/salesGroups'

/**
 * Cégek közötti értékesítő csoport nézet (superadmin) — olvasás + CRUD.
 *
 * A `GET /api/admin/sales-groups` egyetlen, LAPOZATLAN listát ad vissza, cégre
 * rendezve. A cégenkénti csoportosítást mégis itt, kliensoldalon végezzük el
 * (nem a szerver sorrendjére hagyatkozva), hogy a nézet akkor is helyes
 * maradjon, ha a végpont rendezése egyszer megváltozna.
 *
 * ═══ SZERKESZTÉS ═══
 * A csoport LÉTREHOZÁSA / ÁTNEVEZÉSE / TÖRLÉSE innen bármelyik cégben elvégezhető
 * (`POST/PUT/DELETE /api/admin/sales-groups`). A kapu itt NEM permission-kulcs,
 * hanem a `/api/me` superadmin-flagje: a backenden is hard superadmin-kapu áll
 * (l. `AdminSalesGroupController` + `ResolveCrossCompanyContext`), új
 * permission-kulcs szándékosan nincs. A TAGSÁG szerkesztése továbbra is cégen
 * belül, a SalesGroupPage-en történik — ahhoz a cég user-listája kell, ami
 * cross-company úton nem elérhető.
 *
 * Mutáció után MINDIG teljes újratöltés (`load()`), nem optimista lokális patch:
 * a cross-company pillanatkép több cég adatát fogja egybe, így könnyen elavul
 * (ugyanaz a stale-snapshot kockázat, amit a CompanyPage SalesGroupPrefixSection-je
 * is kivéd).
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

/**
 * A csoport SAJÁT cégének prefixe, a lista-válasz `display_name`-jéből
 * visszafejtve (a backend képlete: prefix ? `${prefix}_${name}` : name).
 *
 * Miért nem a cég-listából? Az átnevezés így nem függ a cégválasztóhoz betöltött
 * `GET /api/companies` sikerétől: a prefix mindig a szerkesztett sor SAJÁT
 * adatából jön, tehát az előnézet sosem mutathat téves megjelenítőnevet.
 */
function prefixFromDisplayName(group) {
  const suffix = `_${group.name}`
  return group.display_name.endsWith(suffix)
    ? group.display_name.slice(0, -suffix.length)
    : null
}

/**
 * A cégválasztó teljes cég-listája. A `GET /api/companies` fixen 50-es lapokat
 * ad (a backend nem fogad `per_page`-et), ezért a további lapokat is behúzzuk —
 * különben az 50. utáni cégekbe egyszerűen nem lehetne csoportot létrehozni.
 */
async function fetchAllCompanies() {
  const first    = await companiesApi.list()
  const items    = [...(first.data.data ?? [])]
  const lastPage = first.data.meta?.last_page ?? 1

  if (lastPage > 1) {
    const rest = await Promise.all(
      Array.from({ length: lastPage - 1 }, (_, i) => companiesApi.list({ page: i + 2 }))
    )
    for (const res of rest) items.push(...(res.data.data ?? []))
  }

  return items
}

export default function AdminSalesGroupPage() {
  const { user } = useAuth()
  const { t }    = useTranslation()
  const toast    = useToast()

  // A szerkesztő affordanciák kapuja — NEM permission-kulcs, l. a fájl fejét.
  const canManage = !!user?.is_superadmin

  const [companies, setCompanies] = useState([])
  const [loading, setLoading]     = useState(true)
  const [error, setError]         = useState('')

  // Cégválasztó forrása. Külön kérés, mert a csoport-lista CSAK azokat a cégeket
  // hozza, amelyekben MÁR van csoport — létrehozni viszont üres cégbe is kell.
  const [allCompanies, setAllCompanies]       = useState([])
  const [companiesFailed, setCompaniesFailed] = useState(false)

  const [showCreate, setShowCreate]           = useState(false)
  const [createCompanyId, setCreateCompanyId] = useState(null)
  const [editId, setEditId]                   = useState(null)
  const [saving, setSaving]                   = useState(false)

  async function load() {
    setLoading(true)
    setError('')

    // allSettled, nem all: a cég-lista hibája ne vigye magával a nézet fő
    // tartalmát — olvasni akkor is lehet, ha csak létrehozni nem.
    const [groupsRes, companiesRes] = await Promise.allSettled([
      adminSgApi.list(),
      canManage ? fetchAllCompanies() : Promise.resolve([]),
    ])

    if (groupsRes.status === 'fulfilled') {
      setCompanies(groupByCompany(groupsRes.value.data.data ?? []))
    } else {
      setError(groupsRes.reason?.response?.data?.message ?? t('common.error'))
    }

    setCompaniesFailed(companiesRes.status === 'rejected')
    if (companiesRes.status === 'fulfilled') setAllCompanies(companiesRes.value)

    setLoading(false)
  }

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // A létrehozó és a szerkesztő form kizárja egymást — ugyanaz a viselkedés,
  // mint a cégen belüli SalesGroupPage-en.
  function openCreate(companyId = null) {
    setEditId(null)
    setCreateCompanyId(companyId)
    setShowCreate(true)
  }

  function startEdit(group) {
    setShowCreate(false)
    setEditId(group.id)
  }

  // A hibát SZÁNDÉKOSAN nem kapjuk el: a SalesGroupForm dolgozza fel (mezőhibák a
  // mezők alatt, egyéb a form fejében). Ugyanez a felosztás, mint cégen belül.
  async function handleCreate(name) {
    setSaving(true)
    try {
      await adminSgApi.create({ company_id: createCompanyId, name })
      setShowCreate(false)
      toast('Csoport létrehozva.', 'success')
      await load()
    } finally {
      setSaving(false)
    }
  }

  async function handleUpdate(group, name) {
    setSaving(true)
    try {
      await adminSgApi.update(group.id, { name })
      setEditId(null)
      toast('Csoport átnevezve.', 'success')
      await load()
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(group, companyName) {
    // A backend nem tiltja a tagokkal rendelkező csoport törlését: a
    // sales_group_user sorokat DB-szintű cascade takarítja. A megerősítő kérdés
    // ezt tükrözi, hogy a tagságok elvesztése ne legyen váratlan.
    const memberWarning = group.users.length > 0
      ? ` Ezzel ${group.users.length} tagság is megszűnik.`
      : ''

    if (!confirm(`Törli a(z) „${group.display_name}" csoportot a(z) ${companyName} cégből?${memberWarning}`)) return

    try {
      await adminSgApi.remove(group.id)
      if (editId === group.id) setEditId(null)
      toast('Csoport törölve.', 'success')
      await load()
    } catch (err) {
      toast(describeCrossCompanyError(err), 'error')
    }
  }

  const groupCount    = companies.reduce((sum, c) => sum + c.groups.length, 0)
  const createCompany = allCompanies.find((c) => c.id === createCompanyId) ?? null
  const columnCount   = canManage ? 3 : 2

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Értékesítő csoportok — összes cég</h1>
        {canManage && !showCreate && (
          <button className="btn btn-primary" onClick={() => openCreate()}>
            + Új csoport
          </button>
        )}
      </div>

      <p className="text-muted" style={{ fontSize: 13, marginBottom: 16 }}>
        Áttekintő nézet minden cég értékesítő csoportjairól és tagjairól.{' '}
        {canManage ? (
          <>
            Szuperadminként innen <strong>bármelyik cégben</strong> létrehozhatsz, átnevezhetsz
            és törölhetsz csoportot. A <strong>tagság</strong> szerkesztése továbbra is cégenként,
            az{' '}
            <Link to="/settings/sales-groups" className="table-link">Értékesítő csoportok</Link>{' '}
            oldalon történik, az aktuálisan kiválasztott cégre.
          </>
        ) : (
          <>
            <strong>Csak megtekintés</strong> — a szerkesztés cégenként, az{' '}
            <Link to="/settings/sales-groups" className="table-link">Értékesítő csoportok</Link>{' '}
            oldalon történik, az aktuálisan kiválasztott cégre.
          </>
        )}
      </p>

      {error && <div className="alert-error mb-4">{error}</div>}

      {/* Létrehozó form — cégválasztóval */}
      {showCreate && canManage && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong style={{ display: 'block', marginBottom: 12 }}>Új csoport — cégek között</strong>

          {companiesFailed ? (
            <>
              <div className="alert-error">
                A cégek listája nem tölthető be, ezért most nem hozható létre csoport.
              </div>
              <button className="btn btn-secondary btn-sm" onClick={() => setShowCreate(false)}>
                Mégsem
              </button>
            </>
          ) : (
            <SalesGroupForm
              key="create"
              prefix={createCompany?.group_prefix ?? null}
              onSave={handleCreate}
              onCancel={() => setShowCreate(false)}
              saving={saving}
              // Cél cég nélkül a kérés eleve 422-re futna — inkább nem küldjük el.
              disableSubmit={createCompanyId === null}
              describeError={describeCrossCompanyError}
              renderBeforeFields={(fieldErrors) => (
                <div className="form-group" style={{ margin: 0 }}>
                  <label htmlFor="cross-company-target">Cél cég</label>
                  <select
                    id="cross-company-target"
                    value={createCompanyId ?? ''}
                    onChange={(e) => setCreateCompanyId(e.target.value ? Number(e.target.value) : null)}
                    disabled={saving}
                  >
                    <option value="">— válassz céget —</option>
                    {allCompanies.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}{c.group_prefix ? ` (${c.group_prefix})` : ' — nincs prefix'}
                      </option>
                    ))}
                  </select>

                  {/* A cél cég validációja a company.cross middleware-ben történik,
                      ezért a hibája `errors.company_id`-ként jön vissza. */}
                  {fieldError(fieldErrors, 'company_id') && (
                    <div className="form-error">{fieldError(fieldErrors, 'company_id')}</div>
                  )}

                  {createCompany && !createCompany.group_prefix && (
                    <div className="form-error">
                      Ennek a cégnek nincs értékesítő csoport prefixe — prefix nélkül a szerver
                      elutasítja a létrehozást. Előbb állítsd be a cég beállításainál.
                    </div>
                  )}

                  <div className="text-muted" style={{ fontSize: 12, marginTop: 4 }}>
                    A cég-lista nem közli, mely cégben van bekapcsolva az Értékesítő csoportok
                    modul — kikapcsolt modulú cégnél a mentés hibaüzenetet ad.
                  </div>
                </div>
              )}
            />
          )}
        </div>
      )}

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : !error && companies.length === 0 ? (
        <p className="text-muted">
          Egyetlen cégben sincs értékesítő csoport.
          {canManage && ' Az „+ Új csoport" gombbal bármelyik cégben létrehozhatsz egyet.'}
        </p>
      ) : (
        companies.map((company) => (
          <div className="card" key={company.id ?? company.name}>
            <div style={{
              display: 'flex', alignItems: 'baseline', justifyContent: 'space-between',
              gap: 12, marginBottom: 14,
            }}>
              <h2 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>{company.name}</h2>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span className="text-muted" style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
                  {company.groups.length} csoport
                </span>
                {/* Cég-azonosító nélküli (besorolhatatlan) sorokhoz nincs cél cég,
                    ezért ott nem ajánlunk létrehozást. */}
                {canManage && company.id !== null && (
                  <button
                    className="btn btn-secondary btn-sm"
                    onClick={() => openCreate(company.id)}
                  >
                    + Csoport
                  </button>
                )}
              </div>
            </div>

            <table>
              <thead>
                <tr>
                  <th style={{ width: '30%' }}>Csoport</th>
                  <th>Tagok</th>
                  {canManage && (
                    <th style={{ width: 170, textAlign: 'right' }}>{t('common.actions')}</th>
                  )}
                </tr>
              </thead>
              <tbody>
                {company.groups.map((group) => (
                  <Fragment key={group.id}>
                    <tr>
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
                      {canManage && (
                        <td style={{ textAlign: 'right', whiteSpace: 'nowrap', verticalAlign: 'top' }}>
                          {editId !== group.id && (
                            <>
                              <button
                                className="btn btn-secondary btn-sm"
                                style={{ marginRight: 6 }}
                                onClick={() => startEdit(group)}
                              >
                                {t('common.edit')}
                              </button>
                              <button
                                className="btn btn-danger btn-sm"
                                onClick={() => handleDelete(group, company.name)}
                              >
                                {t('common.delete')}
                              </button>
                            </>
                          )}
                        </td>
                      )}
                    </tr>

                    {/* Inline szerkesztő sor — a szerkesztett csoport ALATT, ugyanazzal a
                        mintával, mint a cégen belüli SalesGroupPage-en. A `key` azért
                        kell, mert a form a nevet mountoláskor veszi át az `initial`-ből:
                        nélküle sorváltáskor az előző csoport neve maradna a mezőben. */}
                    {canManage && editId === group.id && (
                      <tr>
                        <td colSpan={columnCount}>
                          <div style={{ padding: '8px 0' }}>
                            <SalesGroupForm
                              key={`form-${group.id}`}
                              prefix={prefixFromDisplayName(group)}
                              initial={group}
                              onSave={(name) => handleUpdate(group, name)}
                              onCancel={() => setEditId(null)}
                              saving={saving}
                              describeError={describeCrossCompanyError}
                            />
                          </div>
                        </td>
                      </tr>
                    )}
                  </Fragment>
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
