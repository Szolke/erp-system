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

// Locale-független "YYYY-MM-DD HH:mm" időbélyeg, a felhasználó HELYI idejében.
// Szándékosan nem a fenti formatDateTime: az toLocaleString-et hív, ami `hu`
// alatt "2026. 07. 28. 19:34" alakot ad, és locale-onként változik — a blame-
// lábléc viszont minden nyelven ugyanazt a kompakt, rendezhető alakot kéri.
//
// A backend UTC ISO8601-et küld; a Date konstruktor ezt már helyi időre
// értelmezi, ezért a lokális gettereket (getFullYear/getMonth/…) olvassuk, NEM
// a toISOString()-et, ami visszatérne UTC-re.
export function formatTimestamp(isoString) {
  if (!isoString) return ''
  const date = new Date(isoString)
  if (Number.isNaN(date.getTime())) return isoString
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
    + ` ${pad(date.getHours())}:${pad(date.getMinutes())}`
}
