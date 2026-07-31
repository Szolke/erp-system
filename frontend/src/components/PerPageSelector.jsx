import { useTranslation } from '../contexts/TranslationContext'

// A felső határ szándékosan 500: a lapméret mostantól a user_list_preferences
// rétegbe is mentődik, a backend validáció pedig `page_size` mezőre
// `between:5,500`-at enged (UpdateListPreferenceRequest) — egy 1000-es opció
// 422-vel elszállna a mentéskor.
const OPTIONS = [20, 50, 100, 200, 500]

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
