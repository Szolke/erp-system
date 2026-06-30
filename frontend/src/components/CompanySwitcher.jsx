import { useAuth } from '../contexts/AuthContext'

export default function CompanySwitcher() {
  const { companies, activeCompanyId, switchCompany } = useAuth()

  if (companies.length <= 1) {
    return <div className="company-name">{companies[0]?.name ?? '—'}</div>
  }

  return (
    <select
      className="company-select"
      value={activeCompanyId ?? ''}
      onChange={(e) => switchCompany(Number(e.target.value))}
    >
      {companies.map((c) => (
        <option key={c.id} value={c.id}>{c.name}</option>
      ))}
    </select>
  )
}
