import { useEffect, useState } from 'react'
import { jobPositions as jobPositionsApi } from '../api/jobPositions'

/**
 * Controlled <select> for choosing an (optional) job position.
 *
 * Loads the active global+company list from GET /api/job-positions (no
 * job_position.manage permission required — any authenticated user in
 * company context can read it, matching the vat-rates/payment-methods
 * catalog pattern).
 *
 * `currentJobPosition` (the user's already-assigned job_position, if any)
 * is appended to the option list when it's missing from the active list —
 * this happens when the assigned position has since been deactivated, and
 * without it the select would silently drop the existing value.
 */
export default function JobPositionSelect({ value, onChange, currentJobPosition = null, disabled = false, style }) {
  const [options, setOptions] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(false)
    jobPositionsApi.list()
      .then((res) => {
        if (!cancelled) setOptions(res.data.data ?? [])
      })
      .catch(() => {
        if (!cancelled) setError(true)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => { cancelled = true }
  }, [])

  const hasCurrentInList = currentJobPosition && options.some((o) => o.id === currentJobPosition.id)
  const allOptions = currentJobPosition && !hasCurrentInList
    ? [...options, currentJobPosition]
    : options

  return (
    <div>
      <select
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
        disabled={disabled || loading}
        style={style}
      >
        <option value="">— nincs munkakör —</option>
        {allOptions.map((jp) => (
          <option key={jp.id} value={jp.id}>
            {jp.name}{!jp.active ? ' (inaktív)' : ''}
          </option>
        ))}
      </select>
      {error && <p className="text-muted" style={{ fontSize: 12, marginTop: 4 }}>Munkakörök betöltése sikertelen.</p>}
    </div>
  )
}
