import { useTranslation } from '../contexts/TranslationContext'

function pageRange(current, last) {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)
  const set = new Set([1, last, current - 1, current, current + 1].filter(p => p >= 1 && p <= last))
  const sorted = [...set].sort((a, b) => a - b)
  const result = []
  let prev = null
  for (const p of sorted) {
    if (prev !== null && p - prev > 1) result.push('…')
    result.push(p)
    prev = p
  }
  return result
}

export default function Pagination({ meta, onChange }) {
  const { t } = useTranslation()
  if (!meta || meta.last_page <= 1) return null
  const { current_page: curr, last_page: last } = meta

  return (
    <div className="pagination">
      <button className="btn btn-secondary btn-sm" disabled={curr <= 1} onClick={() => onChange(curr - 1)}>
        {t('common.prev_page')}
      </button>
      {pageRange(curr, last).map((p, i) =>
        p === '…'
          ? <span key={`e${i}`} className="pagination-ellipsis">…</span>
          : <button
              key={p}
              className={`btn btn-sm ${p === curr ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => onChange(p)}
              disabled={p === curr}
            >{p}</button>
      )}
      <button className="btn btn-secondary btn-sm" disabled={curr >= last} onClick={() => onChange(curr + 1)}>
        {t('common.next_page')}
      </button>
    </div>
  )
}
