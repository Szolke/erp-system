const OPTIONS = [20, 50, 100, 200, 500, 1000]

export default function PerPageSelector({ value, onChange }) {
  return (
    <label className="per-page-selector">
      Sorok:
      <select value={value} onChange={(e) => onChange(Number(e.target.value))}>
        {OPTIONS.map((n) => (
          <option key={n} value={n}>{n}</option>
        ))}
      </select>
    </label>
  )
}
