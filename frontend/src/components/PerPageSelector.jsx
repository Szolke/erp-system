import { useTranslation } from '../contexts/TranslationContext'

const OPTIONS = [20, 50, 100, 200, 500, 1000]

export default function PerPageSelector({ value, onChange }) {
  const { t } = useTranslation()
  return (
    <label className="per-page-selector">
      {t('common.rows')}:
      <select value={value} onChange={(e) => onChange(Number(e.target.value))}>
        {OPTIONS.map((n) => <option key={n} value={n}>{n}</option>)}
      </select>
    </label>
  )
}
