import { useState } from 'react'
import { fieldError, salesGroupDisplayName } from '../utils/salesGroups'

/** Alap hibaüzenet-képző: a Laravel `message` mezője. */
function defaultDescribeError(error) {
  return error.response?.data?.message ?? 'Hiba'
}

/**
 * Értékesítő csoport név-űrlapja — a cégen belüli (SalesGroupPage) és a cégek
 * közötti (AdminSalesGroupPage) útnak KÖZÖS komponense: ugyanaz a mezőkészlet,
 * ugyanaz a megjelenítőnév-előnézet és ugyanaz a hibakezelés fut mindkettőn.
 * Ami a két úton MÁS, azt két prop fedi le:
 *
 *  - `renderBeforeFields(fieldErrors)` — a cross-company create cégválasztója
 *    kerül ide, a név-mező ELÉ. Szándékosan függvény és nem sima node: a
 *    szerver mezőhibáit (a cégválasztónál `errors.company_id`) a saját mezőjük
 *    alatt kell mutatni, azok pedig itt, a submit-ágban keletkeznek.
 *  - `describeError(error)` — a mezőhöz NEM köthető hiba szövege. A
 *    cross-company úton a 403 szándékos backend-viselkedés (a cél cégben ki van
 *    kapcsolva a modul), amit a Laravel angol default üzenete helyett magyarázó
 *    szöveggel kell mutatni; cégen belül ugyanaz a státusz mást jelentene,
 *    ezért nem közös a szöveg.
 *
 * A `name` NEM trimmelve megy az `onSave`-nek (a hosszt a szerver validálja) —
 * a submit gomb viszont csak nem-üres, trimmelt névre engedélyezett.
 */
export default function SalesGroupForm({
  prefix,
  initial,
  onSave,
  onCancel,
  saving,
  renderBeforeFields,
  disableSubmit = false,
  describeError = defaultDescribeError,
}) {
  const [name, setName] = useState(initial?.name ?? '')
  const [err, setErr]   = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  async function handleSubmit(e) {
    e.preventDefault()
    setErr('')
    setFieldErrors({})
    try {
      await onSave(name)
    } catch (error) {
      // Laravel-alak: { message, errors }. A mezőhibák a saját mezőjük alá
      // kerülnek, a mezőhöz nem köthető üzenet (403, prefix-hiányos 422, 5xx)
      // a form fejébe.
      const errors = error.response?.data?.errors
      setFieldErrors(errors ?? {})
      setErr(errors ? '' : describeError(error))
    }
  }

  const preview = name.trim() ? salesGroupDisplayName(prefix, name.trim()) : ''

  return (
    <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      {err && <div className="alert-error">{err}</div>}
      {renderBeforeFields?.(fieldErrors)}
      <div className="form-group" style={{ margin: 0 }}>
        <label>Csoport neve</label>
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          maxLength={100}
          placeholder="pl. Észak"
          autoFocus
        />
        {fieldError(fieldErrors, 'name') && (
          <div className="form-error">{fieldError(fieldErrors, 'name')}</div>
        )}
      </div>
      {preview && (
        <div style={{ fontSize: 12, color: 'var(--color-muted)' }}>
          Megjelenítőnév:{' '}
          <code style={{ fontSize: 12, color: 'var(--color-text)' }}>{preview}</code>
        </div>
      )}
      <div style={{ display: 'flex', gap: 8 }}>
        <button
          className="btn btn-primary btn-sm"
          type="submit"
          disabled={saving || !name.trim() || disableSubmit}
        >
          {saving ? 'Mentés...' : initial ? 'Mentés' : 'Létrehozás'}
        </button>
        <button className="btn btn-secondary btn-sm" type="button" onClick={onCancel}>
          Mégsem
        </button>
      </div>
    </form>
  )
}
