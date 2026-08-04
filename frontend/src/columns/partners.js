// Partnerlista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mind az 5 adatoszlop rendezhető; a kulcsok 1:1-ben megfelelnek a
// backend whitelistjének (PartnerController::SORTABLE_COLUMNS) — a `city` ott a
// `billing_city` DB-oszlopra képződik le. Az `actions` oszlop nem adat, ezért
// nem rendezhető. Mind szöveges mező, így mindegyik ábécésorrenddel indul.
export const partnerColumns = [
  { key: 'name', label: 'common.name', default: true, locked: true, sortable: true },
  { key: 'tax_number', label: 'partner.tax_number', default: true, sortable: true },
  { key: 'type', label: 'partner.type', default: true, sortable: true },
  { key: 'city', label: 'partner.city', default: true, sortable: true },
  { key: 'email', label: 'common.email', default: true, sortable: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
