import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { salesGroups as sgApi } from '../../api/salesGroups'
import { company as companyApi } from '../../api/company'
import Pagination from '../../components/Pagination'
import PerPageSelector from '../../components/PerPageSelector'
import ColumnPicker from '../../components/ColumnPicker'
import SortableColumnHeader from '../../components/SortableColumnHeader'
import BlameFooter from '../../components/BlameFooter'
import SalesGroupForm from '../../components/SalesGroupForm'
import SalesGroupMembersSection from '../../components/SalesGroupMembersSection'
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

export default function SalesGroupPage() {
  const { can } = useAuth()
  const { t }   = useTranslation()
  const toast   = useToast()

  const [data, setData]         = useState(null)
  const [loading, setLoading]   = useState(true)
  const [prefix, setPrefix]     = useState(null)
  // A lapméret a mentett lista-preferenciából jön (l. useListColumns).
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
  const { allColumns, visibleColumns, isVisible, toggle: toggleColumn, reorder, reset: resetColumns, isDirty, pageSize: perPage, setPageSize, sort, toggleSort } =
    useListColumns('sales_groups.index', salesGroupColumns, { defaultPageSize: 20 })

  async function load(pp = perPage, pg = page) {
    setLoading(true)
    try {
      const [sgRes, compRes] = await Promise.all([
        // Rendezés: a mentett preferenciából (nincs saját rendezés → a végpont a
        // saját alapértelmezését adja, l. SalesGroupController::SORTABLE_COLUMNS).
        sgApi.list({ sort_by: sort?.by, sort_dir: sort?.dir, per_page: pp, page: pg }),
        companyApi.get(),
      ])
      setData(sgRes.data)
      setPrefix(compRes.data.data?.group_prefix ?? null)
    } finally {
      setLoading(false)
    }
  }

  // Ez egyben a MOUNT-effekt is: az első lekérdezés már a mentett rendezéssel
  // indul. Rendezésváltáskor vissza az első oldalra (l. DocumentListPage).
  useEffect(() => { setPage(1); load(perPage, 1) }, [sort]) // eslint-disable-line react-hooks/exhaustive-deps

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
    setPageSize(value)
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
                col.sortable ? (
                  <SortableColumnHeader
                    key={col.key}
                    label={t(col.label)}
                    align={col.align}
                    direction={sort?.by === col.key ? sort.dir : null}
                    onSort={() => toggleSort(col.key)}
                  />
                ) : (
                  <th key={col.key}>{t(col.label)}</th>
                )
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
                    <SalesGroupMembersSection
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
