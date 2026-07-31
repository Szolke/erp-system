import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from '../contexts/TranslationContext'
import { useToast } from '../contexts/ToastContext'
import { salesGroups as sgApi } from '../api/salesGroups'
import { users as usersApi } from '../api/users'

/** Egy keresési találati oldal mérete (üres keresésnél is ennyi jön). */
const CANDIDATE_PAGE_SIZE = 50
/** Gépelés utáni várakozás a keresés elküldéséig — a ProductComboBox mintája. */
const SEARCH_DEBOUNCE_MS = 200

/**
 * Tagság-szekció a csoport szerkesztő sorában.
 *
 * ── Mentési út ──────────────────────────────────────────────────────────
 * A tagság SAJÁT PUT-tal (`/sales-groups/{id}/users`) megy, és CSAK a
 * `user_ids` mezőt küldi, mindig az ÉLŐ `selected` state-ből — nem egy
 * mountoláskor vett pillanatképből. Ezzel elkerüljük ugyanazt a
 * versenyhelyzetet, amit a CompanyPage `SalesGroupPrefixSection`-je is kivéd:
 * két, egymástól független mentési út nem írhatja felül a másik időközbeni
 * változását, mert mindegyik csak a saját mezőit küldi.
 *
 * ── Miért szerver-oldali keresés? ───────────────────────────────────────
 * Korábban a szekció EGYSZER lekérte a cég első 200 felhasználóját
 * (`per_page: 200`), és a szűrés kliens-oldalon futott. Ez 200 fő fölött nem
 * csak lassú volt, hanem FUNKCIONÁLISAN HIBÁS: a névsor 200. helye utáni
 * felhasználó egyáltalán nem volt kiválasztható, mert a keresőmező is csak a
 * már letöltött részhalmazon szűrt. Most a keresés a `/api/users` `search`
 * paraméterére megy (a UserListPage-en már éles), oldalanként 50 találattal —
 * a cég mérete így nem korlátoz.
 *
 * ── Miért kell külön „Kiválasztott tagok" blokk? ────────────────────────
 * Ha a találati lista cserélődik, a bepipált, de az aktuális keresésbe nem
 * illő tag eltűnne a képernyőről — a felhasználó nem látná, kiket ment el.
 * Ezért a kiválasztás egy külön, felül rögzített blokkban MINDIG látszik,
 * a keresési találatoktól függetlenül. A blokk a `userIndex`-ből dolgozik:
 * ez egy id → user térkép, amit a tagság-lekérés tölt fel, és minden keresési
 * találat továbbbővít — így egy korábbi keresésből kiválasztott felhasználó
 * neve akkor is megvan, ha már rég kigörgött a találatok közül.
 *
 * A két blokk affordanciája szándékosan KÜLÖNBÖZŐ (fent „×" eltávolítás, lent
 * checkbox), hogy ugyanahhoz a művelethez ne legyen két, egymás mellett élő
 * azonos kinézetű vezérlő.
 */
export default function SalesGroupMembersSection({ groupId, canEdit, canViewUsers, onDirtyChange }) {
  const { t }  = useTranslation()
  const toast  = useToast()

  const [members, setMembers]   = useState([])   // szerver szerinti aktuális tagság
  const [selected, setSelected] = useState(new Set())
  const [original, setOriginal] = useState(new Set())
  // id → { id, name, email }: minden valaha látott felhasználó, hogy a
  // kiválasztott blokk a keresés cserélődése után is tudjon nevet mutatni.
  const [userIndex, setUserIndex] = useState(new Map())

  const [candidates, setCandidates] = useState([])   // aktuális keresési találatok
  const [search, setSearch]         = useState('')
  const [searching, setSearching]   = useState(false)
  const [searchError, setSearchError] = useState('')

  const [loading, setLoading]   = useState(true)
  const [saving, setSaving]     = useState(false)
  const [error, setError]       = useState('')

  // A tagság szerkesztéséhez a `sales_group.edit` mellett a céges user-listázás
  // joga is kell, mert a jelöltek a `/api/users`-ről jönnek.
  const editable = canEdit && canViewUsers

  /** Új felhasználó-adatok beolvasztása az indexbe (változatlan tartalomnál stabil referencia). */
  function indexUsers(list) {
    setUserIndex((prev) => {
      let changed = false
      const next = new Map(prev)
      for (const u of list) {
        const old = next.get(u.id)
        if (!old || old.name !== u.name || old.email !== u.email) {
          next.set(u.id, u)
          changed = true
        }
      }
      // Ha nem változott semmi, ugyanazt a Map-et adjuk vissza — így a
      // kiválasztott blokk useMemo-ja nem számol újra minden kereséskor.
      return changed ? next : prev
    })
  }

  // ── Tagság betöltése ──────────────────────────────────────────────────
  // A komponens a szerkesztett csoportra van kulcsolva, így csoportváltásnál
  // újramountol. A `cancelled` őr megakadályozza, hogy egy elkésett válasz egy
  // már lecsukott/másik sor állapotát írja felül.
  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')

    sgApi.listUsers(groupId)
      .then((res) => {
        if (cancelled) return
        const memberList = res.data.data ?? []
        const ids = new Set(memberList.map((u) => u.id))
        setMembers(memberList)
        setSelected(ids)
        setOriginal(ids)
        indexUsers(memberList)
      })
      .catch((err) => {
        if (cancelled) return
        setError(err.response?.data?.message ?? t('common.error'))
      })
      .finally(() => { if (!cancelled) setLoading(false) })

    return () => { cancelled = true }
  }, [groupId]) // eslint-disable-line react-hooks/exhaustive-deps

  // ── Jelöltek keresése (debounced) ─────────────────────────────────────
  // A cleanup egyszerre oldja meg a debounce-ot és a versenyhelyzetet: a még
  // el nem indult kérést törli az időzítővel, a már elindultat a `cancelled`
  // őrrel ejti el — így mindig az UTOLSÓ leütés eredménye marad a képernyőn.
  useEffect(() => {
    if (!editable) {
      setCandidates([])
      return
    }

    const q = search.trim()
    let cancelled = false
    setSearching(true)
    setSearchError('')

    // Üres keresésnél (megnyitáskor és a mező kiürítésekor) nincs mire várni,
    // azonnal lövünk; gépelésnél viszont kivárjuk a debounce-t.
    const delay = q ? SEARCH_DEBOUNCE_MS : 0

    const timer = setTimeout(() => {
      usersApi.list({ search: q || undefined, per_page: CANDIDATE_PAGE_SIZE })
        .then((res) => {
          if (cancelled) return
          const list = res.data.data ?? []
          setCandidates(list)
          indexUsers(list)
        })
        .catch((err) => {
          if (cancelled) return
          setCandidates([])
          setSearchError(err.response?.data?.message ?? t('common.error'))
        })
        .finally(() => { if (!cancelled) setSearching(false) })
    }, delay)

    return () => { cancelled = true; clearTimeout(timer) }
  }, [search, editable]) // eslint-disable-line react-hooks/exhaustive-deps

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
      // Az élő `selected` state-ből — nem mount-kori snapshotból, és nem az
      // aktuális találati oldalból: a kiválasztás a keresések között végig él.
      const res = await sgApi.syncUsers(groupId, [...selected])
      const saved = res.data.data ?? []
      const ids   = new Set(saved.map((u) => u.id))
      // A szerver válaszából állítjuk vissza az állapotot: az marad a mérvadó,
      // ami ténylegesen mentődött.
      setMembers(saved)
      setSelected(ids)
      setOriginal(ids)
      indexUsers(saved)
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

  // A kiválasztott tagok névsora. Memoizált: a `localeCompare`-es rendezés
  // korábban a render-törzsben, minden leütésre újrafutott — most csak akkor
  // számol, ha a kiválasztás vagy az index ténylegesen változik.
  const selectedUsers = useMemo(() => (
    [...selected]
      .map((id) => userIndex.get(id))
      .filter(Boolean)
      .sort((a, b) => a.name.localeCompare(b.name, 'hu'))
  ), [selected, userIndex])

  if (loading) {
    return (
      <div style={{ marginTop: 16 }}>
        <p className="text-muted" style={{ fontSize: 13 }}>{t('common.loading')}</p>
      </div>
    )
  }

  // Csak-olvasó módban a szerver szerinti tagságot mutatjuk; jelöltet nem
  // töltünk, mert kiválasztani úgysem lehetne őket.
  const readOnlyList = members

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

      {!editable ? (
        // ── Csak-olvasó nézet ────────────────────────────────────────────
        readOnlyList.length === 0 ? (
          <p className="text-muted" style={{ fontSize: 13 }}>Ennek a csoportnak nincs tagja.</p>
        ) : (
          <ul style={{ listStyle: 'none', padding: 0, margin: 0, maxHeight: 260, overflowY: 'auto' }}>
            {readOnlyList.map((u) => (
              <li key={u.id} style={{ fontSize: 13, padding: '2px 0' }}>
                {u.name} <span className="text-muted" style={{ fontSize: 12 }}>({u.email})</span>
              </li>
            ))}
          </ul>
        )
      ) : (
        <>
          {/* ── Kiválasztott tagok (rögzített, keresés-független) ───────── */}
          <div style={{ marginBottom: 14 }}>
            <div className="text-muted" style={{ fontSize: 12, marginBottom: 6 }}>
              Kiválasztott tagok
            </div>
            {selectedUsers.length === 0 ? (
              <p className="text-muted" style={{ fontSize: 13, margin: 0 }}>
                Még nincs kiválasztott tag.
              </p>
            ) : (
              <div style={{
                display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))',
                gap: '4px 20px', maxHeight: 200, overflowY: 'auto',
              }}>
                {selectedUsers.map((u) => (
                  <span key={u.id} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
                    <button
                      type="button"
                      className="btn btn-secondary btn-sm"
                      onClick={() => toggle(u.id)}
                      disabled={saving}
                      title="Eltávolítás a csoportból"
                      aria-label={`${u.name} eltávolítása`}
                      style={{ lineHeight: 1, padding: '0 6px' }}
                    >
                      ×
                    </button>
                    <span>
                      {u.name} <span className="text-muted" style={{ fontSize: 12 }}>({u.email})</span>
                    </span>
                  </span>
                ))}
              </div>
            )}
          </div>

          {/* ── Keresés a cég felhasználói között ───────────────────────── */}
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Keresés névre / e-mailre"
            aria-label="Keresés névre / e-mailre"
            style={{ maxWidth: 280, marginBottom: 10 }}
          />

          {searchError && <div className="alert-error mb-4">{searchError}</div>}

          {searching ? (
            <p className="text-muted" style={{ fontSize: 13 }}>{t('common.loading')}</p>
          ) : candidates.length === 0 ? (
            <p className="text-muted" style={{ fontSize: 13 }}>
              {searchError ? 'A keresés nem sikerült.' : 'Nincs találat.'}
            </p>
          ) : (
            <>
              <div style={{
                display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))',
                gap: '4px 20px', maxHeight: 260, overflowY: 'auto',
              }}>
                {candidates.map((u) => (
                  <label
                    key={u.id}
                    style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}
                  >
                    <input
                      type="checkbox"
                      checked={selected.has(u.id)}
                      onChange={() => toggle(u.id)}
                      disabled={saving}
                      style={{ width: 'auto' }}
                    />
                    <span style={{ fontSize: 13 }}>
                      {u.name} <span className="text-muted" style={{ fontSize: 12 }}>({u.email})</span>
                    </span>
                  </label>
                ))}
              </div>
              {candidates.length >= CANDIDATE_PAGE_SIZE && (
                <p className="text-muted" style={{ fontSize: 12, marginTop: 8 }}>
                  Csak az első {CANDIDATE_PAGE_SIZE} találat látszik — szűkíts a kereséssel.
                </p>
              )}
            </>
          )}
        </>
      )}
    </div>
  )
}
