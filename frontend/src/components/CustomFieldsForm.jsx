/**
 * Renders dynamic custom field inputs based on `definitions`.
 * `values` is an object { key: value }, `onChange(key, value)` updates it.
 */
export default function CustomFieldsForm({ definitions, values = {}, onChange }) {
  if (!definitions || definitions.length === 0) return null

  const active = definitions.filter((d) => d.is_active)
  if (active.length === 0) return null

  function handleChange(key, value) {
    onChange({ ...values, [key]: value })
  }

  return (
    <>
      {active.map((def) => (
        <div className="form-group" key={def.key}>
          <label>
            {def.label}
            {def.is_required && <span style={{ color: '#ef4444', marginLeft: 2 }}>*</span>}
          </label>

          {def.type === 'text' && (
            <input
              value={values[def.key] ?? ''}
              onChange={(e) => handleChange(def.key, e.target.value)}
              required={def.is_required}
            />
          )}

          {def.type === 'number' && (
            <input
              type="number"
              value={values[def.key] ?? ''}
              onChange={(e) => handleChange(def.key, e.target.value)}
              required={def.is_required}
            />
          )}

          {def.type === 'date' && (
            <input
              type="date"
              value={values[def.key] ?? ''}
              onChange={(e) => handleChange(def.key, e.target.value)}
              required={def.is_required}
            />
          )}

          {def.type === 'boolean' && (
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={!!values[def.key]}
                onChange={(e) => handleChange(def.key, e.target.checked)}
              />
              {def.label}
            </label>
          )}

          {def.type === 'select' && (
            <select
              value={values[def.key] ?? ''}
              onChange={(e) => handleChange(def.key, e.target.value)}
              required={def.is_required}
            >
              <option value="">—</option>
              {(def.options ?? []).map((opt) => (
                <option key={opt} value={opt}>{opt}</option>
              ))}
            </select>
          )}
        </div>
      ))}
    </>
  )
}
