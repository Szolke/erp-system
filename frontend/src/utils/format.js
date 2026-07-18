// Money value from the backend can be a number or a numeric string
// (see docs/progress.md, Nyitott pontok #16) — Number() normalizes both.
export function formatCurrency(value, currency, locale = 'hu') {
  const amount = Number(value)
  const formatted = Number.isFinite(amount) ? amount.toLocaleString(locale) : String(value)
  return currency ? `${formatted} ${currency}` : formatted
}

export function formatDate(isoString, locale = 'hu') {
  if (!isoString) return ''
  const date = new Date(isoString)
  if (Number.isNaN(date.getTime())) return isoString
  return date.toLocaleDateString(locale)
}

export function formatDateTime(isoString, locale = 'hu') {
  if (!isoString) return ''
  const date = new Date(isoString)
  if (Number.isNaN(date.getTime())) return isoString
  return date.toLocaleString(locale)
}
