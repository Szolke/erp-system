// Céglista (szuperadmin) oszlop-regisztere — a useListColumns hook ebből
// olvassa ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const companyColumns = [
  { key: 'name', label: 'company.name', default: true, locked: true },
  { key: 'tax_number', label: 'company.tax_number', default: true },
  { key: 'city', label: 'company.city', default: true },
  { key: 'users_count', label: 'company.users_count_col', default: true },
  { key: 'status', label: 'company.status_col', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
