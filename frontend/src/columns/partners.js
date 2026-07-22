// Partnerlista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const partnerColumns = [
  { key: 'name', label: 'common.name', default: true, locked: true },
  { key: 'tax_number', label: 'partner.tax_number', default: true },
  { key: 'type', label: 'partner.type', default: true },
  { key: 'city', label: 'partner.city', default: true },
  { key: 'email', label: 'common.email', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
