// Céglista (szuperadmin) oszlop-regisztere — a useListColumns hook ebből
// olvassa ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mind az 5 adatoszlop rendezhető; a kulcsok 1:1-ben megfelelnek a
// backend whitelistjének (CompanyController::SORTABLE_COLUMNS) — a `status` ott
// az `is_active`, a `users_count` a `withCount`-ból származó SELECT-aliasra
// képződik le. Az `actions` oszlop nem adat, ezért nem rendezhető.
export const companyColumns = [
  { key: 'name', label: 'company.name', default: true, locked: true, sortable: true },
  { key: 'tax_number', label: 'company.tax_number', default: true, sortable: true },
  { key: 'city', label: 'company.city', default: true, sortable: true },
  { key: 'users_count', label: 'company.users_count_col', default: true, sortable: true, sortInitialDir: 'desc' },
  { key: 'status', label: 'company.status_col', default: true, sortable: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
