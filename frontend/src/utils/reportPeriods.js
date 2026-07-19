// Hónap-tartomány segédfüggvények a Kimutatások szűrősávjához (from/to
// mindig 'YYYY-MM' alakban, ahogy a backend ReportService is várja).

function ym(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

export function thisMonthRange() {
  const now = new Date()
  return { from: ym(now), to: ym(now) }
}

export function prevMonthRange() {
  const now = new Date()
  const prev = new Date(now.getFullYear(), now.getMonth() - 1, 1)
  return { from: ym(prev), to: ym(prev) }
}

export function thisYearRange() {
  const now = new Date()
  return { from: `${now.getFullYear()}-01`, to: ym(now) }
}

export function prevYearRange() {
  const now = new Date()
  const year = now.getFullYear() - 1
  return { from: `${year}-01`, to: `${year}-12` }
}

export function todayStr() {
  const now = new Date()
  return `${ym(now)}-${String(now.getDate()).padStart(2, '0')}`
}
