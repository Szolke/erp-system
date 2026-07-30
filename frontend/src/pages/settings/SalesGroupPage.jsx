import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { salesGroups as sgApi } from '../../api/salesGroups'
import { users as usersApi } from '../../api/users'
import { company as companyApi } from '../../api/company'
import Pagination from '../../components/Pagination'
import PerPageSelector from '../../components/PerPageSelector'
import ColumnPicker from '../../components/ColumnPicker'
import BlameFooter from '../../components/BlameFooter'
import SalesGroupForm from '../../components/SalesGroupForm'
import { useListColumns } from '../../hooks/useListColumns'
import { salesGroupColumns } from '../../columns/salesGroups'

function renderCell(key, g, { t, can, editId, onEditStart, onDelete }) {
  switch (key) {
    case 'display_name':
      return <td key="display_name"><code style={{ fontSize: 13 }}>{g.display_name}</code></td>
    case 'name':
      return <td key="name" className="text-muted" style={{ fontSize: 13 }}>{g.name}</td>
    case 'actions':
      return (
        <td key="actions" style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
          {editId !== g.id ? (
            <>
              {can('sales_group.edit') && (
                <button
                  className="btn btn-secondary btn-sm"
                  style={{ marginRight: 6 }}
                  onClick={() => onEditStart(g)}
                >
                  {t('common.edit')}
                </button>
              )}
              {can('sales_group.delete') && (
                <button
                  className="btn btn-danger btn-sm"
                  onClick={() => onDelete(g)}
                >
                  {t('common.delete')}
                </button>
              )}
            </>
          ) : null}
        </td>
      )
    default:
      return null
  }
}

/**
 * Tagság-szekció a csoport szerkesztő sorában.
 *
 * Külön mentési út: a tagság SAJÁT PUT-tal (`/sales-groups/{id}/users`) megy,
 * és CSAK a `user_ids` mezőt küldi, mindig az ÉLŐ `selected` state-ből — nem egy
 * mountoláskor vett pillanatképből. Ezzel elkerüljük ugyanazt a versenyhelyzetet,
 * amit a CompanyPage `SalesGroupPrefixSection`-je is kivéd: két, egymástól
 * független mentési út nem írhatja felül a másik időközbeni változását, mert
 * mindegyik csak a saját mezőit küldi.
 *
 * A tagságot mindig frissen töltjük (GET a szekció megnyitásakor), nem a
 * lista-válaszból származtatjuk.
 */
function MembersSection({ groupId, canEdit, canViewUsers, onDirtyChange }) {
  const { t }  = useTranslation()
  const toast  = useToast()

  const [companyUsers, setCompanyUsers] = useState([])
  const [members, setMembers]   = useState([])   // szerver szerinti aktuális tagság
  const [selected, setSelected] = useState(new Set())
  const [original, setOriginal] = useState(new Set())
  const [truncated, setTruncated] = useState(false)
  const [loading, setLoading]   = useState(true)
  const [saving, setSaving]     = useState(false)
  const [error, setError]       = useState('')
  const [filter, setFilter]     = useState('')

  // Betöltés a szekció megnyitásakor (a komponens a szerkesztett csoportra van
  // kulcsolva, így csoportváltásnál újramountol). A `cancelled` őr megakadályozza,
  // hogy egy elkésett válasz egy már lecsukott/másik sor állapotát írja felül.
  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')

    const requests = [sgApi.listUsers(groupId)]
    // A cégre szűkített user-listázó `user.view` jogot kér. Jog nélkül nem
    // küldünk felesleges, 403-ra futó kérést — a szekció ilyenkor csak-olvasás.
    requests.push(canViewUsers ? usersApi.list({ per_page: 200 }) : Promise.resolve(null))

    Promise.all(requests)
      .then(([memberRes, userRes]) => {
        if (cancelled) return
        const memberList = memberRes.data.data ?? []
        const ids = new Set(memberList.map((u) => u.id))
        setMembers(memberList)
        setSelected(ids)
        setOriginal(ids)
        if (userRes) {
          const list = userRes.data.data ?? []
          setCompanyUsers(list)
          setTruncated((userRes.data.meta?.total ?? list.length) > list.length)
        }
      })
      .catch((err) => {
        if (cancelled) return
        setError(err.response?.data?.message ?? t('common.error'))
      })
      .finally(() => { if (!cancelled) setLoading(false) })

    return () => { cancelled = true }
  }, [groupId, canViewUsers]) // eslint-disable-line react-hooks/exhaustive-deps

  function toggle(userId) {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(userId)) next.delete(userId)
      else next.add(userId)
      return next
    })
  }

  async function handleSave() {
    setSaving(true)
    setError('')
    try {
      // Az élő `selected` state-ből — nem mount-kori snapshotból.
      const res = await sgApi.syncUsers(groupId, [...selected])
      const saved = res.data.data ?? []
      const ids   = new Set(saved.map((u) => u.id))
      // A szerver válaszából állítjuk vissza az állapotot: az marad a mérvadó,
      // ami ténylegesen mentődött.
      setMembers(saved)
      setSelected(ids)
      setOriginal(ids)
      toast('Tagság mentve.', 'success')
    } catch (err) {
      // A backend scope-leak-őre (SyncSalesGroupUsersRequest) idegen cég
      // user_id-jára 422-t ad, mezőnkénti üzenettel — ezt mutatjuk meg.
      const errs = err.response?.data?.errors
      const msg  = errs
        ? Object.values(errs).flat().join(' | ')
        : err.response?.data?.message ?? t('common.error')
      setError(msg)
      toast(msg, 'error')
    } finally {
      setSaving(false)
    }
  }

  const dirty = selected.size !== original.size || [...selected].some((id) => !original.has(id))

  // A tagság-mentés független a csoport-űrlapétól, de a fő űrlap mentése bezárja
  // a szerkesztő sort — a szülő ebből tudja, hogy van-e elveszíthető módosítás.
  useEffect(() => {
    onDirtyChange(dirty)
    return () => onDirtyChange(false)
  }, [dirty, onDirtyChange])

  if (loading) {
    return (
      <div style={{ marginTop: 16 }}>
        <p className="text-muted" style={{ fontSize: 13 }}>{t('common.loading')}</p>
      </div>
    )
  }

  // A választható sorok: a cég userei + a jelenlegi tagok. Az unió azért kell,
  // hogy egy olyan tag se essen ki csendben a mentésnél, aki (lapozási korlát
  // vagy időközbeni cég-leválasztás miatt) nincs benne a lekért user-listában.
  const optionList = canViewUsers
    ? [...companyUsers, ...members.filter((m) => !companyUsers.some((u) => u.id === m.id))]
        .sort((a, b) => a.name.localeCompare(b.name, 'hu'))
    : members

  const needle   = filter.trim().toLowerCase()
  const visible  = needle
    ? optionList.filter((u) => `${u.name} ${u.email}`.toLowerCase().includes(needle))
    : optionList

  const editable = canEdit && canViewUsers

  return (
    <div style={{ marginTop: 16, borderTop: '1px solid var(--color-border)', paddingTop: 14 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 10 }}>
        <strong style={{ fontSize: 14 }}>
          Tagok{' '}
          <span className="text-muted" style={{ fontWeight: 400, fontSize: 12 }}>
            ({selected.size} kiválasztva)
          </span>
        </strong>
        {editable && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            {dirty && (
              <span className="text-muted" style={{ fontSize: 12 }}>Nem mentett módosítás</span>
            )}
            <button
              className="btn btn-primary btn-sm"
              type="button"
              onClick={handleSave}
              disabled={saving || !dirty}
            >
              {saving ? t('common.saving') : 'Tagság mentése'}
            </button>
          </div>
        )}
      </div>

      <p className="text-muted" style={{ fontSize: 12, marginBottom: 10 }}>
        A tagság mentése <strong>külön</strong> történik, a csoport nevének mentésétől függetlenül.
      </p>

      {error && <div className="alert-error mb-4">{error}</div>}

      {!canViewUsers && (
        <div className="text-muted" style={{ fontSize: 12, marginBottom: 10 }}>
          A tagok szerkesztéséhez a céges felhasználók listázási joga (<code>user.view</code>) is
          szükséges — jelenleg csak a meglévő tagság látszik.
        </div>
      )}
      {!canEdit && canViewUsers && (
        <div className="text-muted" style={{ fontSize: 12, marginBottom: 10 }}>
          Csak megtekintés — a tagság módosításához <code>sales_group.edit</code> jog szükséges.
        </div>
      )}
      {truncated && (
        <div className="text-muted" style={{ fontSize: 12, marginBottom: 10 }}>
          A cégnek 200-nál több felhasználója van, a lista csak az első 200-at mutatja.
        </div>
      )}

      {optionList.length > 8 && (
        <input
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          placeholder="Szűrés névre / e-mailre"
          style={{ maxWidth: 280, marginBottom: 10 }}
        />
      )}

      {optionList.length === 0 ? (
        <p className="text-muted" style={{ fontSize: 13 }}>
          {canViewUsers ? 'Nincs felhasználó ebben a cégben.' : 'Ennek a csoportnak nincs tagja.'}
        </p>
      ) : (
        <div style={{
          display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))',
          gap: '4px 20px', maxHeight: 260, overflowY: 'auto',
        }}>
          {visible.map((u) => (
            <label
              key={u.id}
              style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: editable ? 'pointer' : 'default' }}
            >
              <input
                type="checkbox"
                checked={selected.has(u.id)}
                onChange={() => toggle(u.id)}
                disabled={!editable || saving}
                style={{ width: 'auto' }}
              />
              <span style={{ fontSize: 13 }}>
                {u.name} <span className="text-muted" style={{ fontSize: 12 }}>({u.email})</span>
              </span>
            </label>
          ))}
          {visible.length === 0 && (
            <p className="text-muted" style={{ fontSize: 13 }}>Nincs találat.</p>
          )}
        </div>
      )}
    </div>
  )
}

export default function SalesGroupPage() {
  const { can } = useAuth()
  const { t }   = useTranslation()
  const toast   = useToast()

  const [data, setData]         = useState(null)
  const [loading, setLoading]   = useState(true)
  const [prefix, setPrefix]     = useState(null)
  const [perPage, setPerPage]   = useState(20)
  const [page, setPage]         = useState(1)

  const [showCreate, setShowCreate] = useState(false)
  const [editId, setEditId]         = useState(null)
  // Az értékesítő csoportnak nincs önálló detail-oldala, a szerkesztés a listán
  // belül történik. A lista (index) válasza viszont szándékosan NEM hozza a
  // blame-adatot (a Resource whenLoaded() kapuja kihagyja), ezért szerkesztés
  // megnyitásakor egyszer lekérjük a detail-végpontot — ott már benne van.
  const [editBlame, setEditBlame]   = useState({})
  const editIdRef                   = useRef(null)
  // A tagság-szekció jelzi, ha van el nem mentett módosítása (a setter azonossága
  // stabil, ezért biztonságosan átadható a gyereknek effect-függőségként).
  const [membersDirty, setMembersDirty] = useState(false)
  const [saving, setSaving]         = useState(false)
  const [columnsOpen, setColumnsOpen] = useState(false)
  const { allColumns, visibleColumns, isVisible, toggle: toggleColumn, reorder, reset: resetColumns, isDirty } = useListColumns('sales_groups.index', salesGroupColumns)

  async function load(pp = perPage, pg = page) {
    setLoading(true)
    try {
      const [sgRes, compRes] = await Promise.all([
        sgApi.list({ per_page: pp, page: pg }),
        companyApi.get(),
      ])
      setData(sgRes.data)
      setPrefix(compRes.data.data?.group_prefix ?? null)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // Szerkesztés megnyitása: a blame-adatot a detail-végpontról kérjük.
  // A válasz beírása előtt ellenőrizzük, hogy még mindig ugyanaz a sor van-e
  // nyitva — gyors sorváltásnál különben egy elkésett válasz felülírná az újat.
  function startEdit(group) {
    // Sorváltáskor a tagság-szekció újramountol — a ki nem mentett kijelölés elveszne.
    if (membersDirty && group.id !== editId
      && !confirm('A tagságon van el nem mentett módosítás, ami másik csoportra váltva elveszik. Folytatod?')) return
    setEditId(group.id)
    editIdRef.current = group.id
    setShowCreate(false)
    setEditBlame({})
    sgApi.get(group.id)
      .then((res) => {
        if (editIdRef.current !== group.id) return
        const g = res.data.data
        setEditBlame({ created_by: g.created_by, updated_by: g.updated_by })
      })
      .catch(() => { /* a lábléc ilyenkor egyszerűen nem jelenik meg */ })
  }

  function cancelEdit() {
    setEditId(null)
    editIdRef.current = null
    setEditBlame({})
  }

  function handlePerPage(value) {
    setPerPage(value)
    setPage(1)
    load(value, 1)
  }

  async function handleCreate(name) {
    setSaving(true)
    try {
      await sgApi.create({ name })
      setShowCreate(false)
      toast('Csoport létrehozva.', 'success')
      load()
    } finally {
      setSaving(false)
    }
  }

  async function handleUpdate(id, name) {
    // A csoport-űrlap mentése bezárja a szerkesztő sort, azzal együtt a tagság-
    // szekciót is. A két mentés független (egymás adatát nem írják felül), de a
    // ki nem mentett kijelölés elveszne — ezért itt rákérdezünk.
    if (membersDirty && !confirm('A tagságon van el nem mentett módosítás, ami a csoport mentésekor elveszik. Folytatod?')) return
    setSaving(true)
    try {
      await sgApi.update(id, { name })
      cancelEdit()
      toast('Csoport módosítva.', 'success')
      load()
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(group) {
    if (!confirm(`Törli a(z) „${group.display_name}" csoportot?`)) return
    try {
      await sgApi.remove(group.id)
      toast('Csoport törölve.', 'success')
      load()
    } catch (err) {
      toast(err.response?.data?.message ?? t('common.error'), 'error')
    }
  }

  const list = data?.data ?? []

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Értékesítő csoportok</h1>
        {can('sales_group.create') && !showCreate && (
          <button className="btn btn-primary" onClick={() => { setShowCreate(true); cancelEdit() }}>
            + Új csoport
          </button>
        )}
      </div>

      {/* Prefix-hiány figyelmeztetés */}
      {!loading && prefix === null && (
        <div className="alert-error mb-4" style={{ fontWeight: 500 }}>
          Előbb állíts be egy <strong>értékesítő csoport prefixet</strong> a{' '}
          <Link
            to="/company#section-sales-group-prefix"
            style={{ color: 'inherit', textDecoration: 'underline' }}
          >
            Cégbeállításoknál
          </Link>
          . Prefix nélkül nem hozható létre csoport.
        </div>
      )}

      {/* Létrehozó form */}
      {showCreate && can('sales_group.create') && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong style={{ display: 'block', marginBottom: 12 }}>Új csoport</strong>
          <SalesGroupForm
            prefix={prefix}
            onSave={handleCreate}
            onCancel={() => setShowCreate(false)}
            saving={saving}
          />
        </div>
      )}

      <div className="search-row">
        <PerPageSelector value={perPage} onChange={handlePerPage} />
        <ColumnPicker
          columns={allColumns} isVisible={isVisible} onToggle={toggleColumn} onReorder={reorder} onReset={resetColumns} isDirty={isDirty}
          open={columnsOpen} onOpenChange={setColumnsOpen} id="sales-groups-columns" align="right"
        />
      </div>

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <table>
          <thead>
            <tr>
              {visibleColumns.map((col) => (
                <th key={col.key}>{t(col.label)}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {list.length === 0 && (
              <tr>
                <td colSpan={visibleColumns.length} className="text-muted">
                  {prefix ? 'Még nincs értékesítő csoport.' : 'Prefix beállítása után hozható létre csoport.'}
                </td>
              </tr>
            )}
            {list.map((g) => (
              <tr key={g.id}>
                {visibleColumns.map((col) => renderCell(col.key, g, {
                  t, can, editId,
                  onEditStart: startEdit,
                  onDelete: handleDelete,
                }))}
              </tr>
            ))}
            {/* Szerkesztő sor — a szerkesztett elem alatt */}
            {editId !== null && (
              <tr>
                <td colSpan={visibleColumns.length}>
                  <div style={{ padding: '8px 0' }}>
                    {/* `key`: a SalesGroupForm a nevet mountoláskor veszi át az
                        `initial`-ből (nem kontrollált mező). `key` nélkül sorváltáskor a
                        komponens újrafelhasználódna, és az ELŐZŐ csoport neve maradna a
                        mezőben. A prefix azért kell a kulcsba, mert testvér elemek
                        kulcsának EGYEDINEK kell lennie — két testvér azonos kulccsal a
                        régi példány törlését akadályozza meg (duplán jelenne meg az
                        űrlap). */}
                    <SalesGroupForm
                      key={`form-${editId}`}
                      prefix={prefix}
                      initial={list.find((g) => g.id === editId)}
                      onSave={(name) => handleUpdate(editId, name)}
                      onCancel={cancelEdit}
                      saving={saving}
                    />
                    {/* A tagság saját mentési úttal rendelkezik — a `key` miatt
                        csoportváltásnál újramountol, így mindig friss adatot tölt.
                        A `members-` előtag a testvér GroupForm kulcsától különbözteti meg. */}
                    <MembersSection
                      key={`members-${editId}`}
                      groupId={editId}
                      canEdit={can('sales_group.edit')}
                      canViewUsers={can('user.view')}
                      onDirtyChange={setMembersDirty}
                    />
                    <BlameFooter createdBy={editBlame.created_by} updatedBy={editBlame.updated_by} />
                  </div>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      )}

      {data && (
        <p className="text-muted mt-4">
          {t('common.total')}: {data.meta?.total} {t('common.pieces')}
        </p>
      )}
      <Pagination
        meta={data?.meta}
        onChange={(p) => { setPage(p); load(perPage, p) }}
      />
    </div>
  )
}
