// Bizonylatlista (számla + nyugta + sztornó egyesített lista) oszlop-regisztere
// — a useListColumns hook ebből olvassa ki, mely oszlopok léteznek, mi az
// alapértelmezett láthatóságuk és sorrendjük. A `label` egy t()-fordítási
// kulcs, hogy az oszlopválasztó ÉS a táblázat fejléce is a projekt
// i18n-rendszerén (HU/EN/DE) menjen át.
//
// A nyugtának NINCS fizetési ciklusa és határideje (l. backend
// DocumentController::buildUnionSql() — `due_date`/`payment_status` a nyugta-
// ágon NULL-ként jön), ezért ezek az oszlopok nyugtasoroknál üresen (—)
// jelennek meg — ez szándékos, nem hiba. Az eredeti 5 oszlop maradt az
// alapértelmezett látható halmaz; a többi a számla adatgazdagsága miatt vált
// elérhetővé, de csak opcionálisan (default: false), hogy a jelenlegi
// felhasználók nézete ne teljen meg hirtelen extra oszloppal.
export const documentColumns = [
  { key: 'number', label: 'document.number', default: true, locked: true },
  { key: 'type', label: 'document.type_col', default: false },
  { key: 'partner', label: 'document.partner', default: true },
  { key: 'partner_tax_number', label: 'document.partner_tax_number', default: false },
  { key: 'issue_date', label: 'document.issued_at', default: true },
  { key: 'fulfillment_date', label: 'document.fulfillment_date', default: false },
  { key: 'due_date', label: 'document.due_date_col', default: false },
  { key: 'net_total', label: 'document.net', default: false, align: 'right' },
  { key: 'vat_total', label: 'document.vat', default: false, align: 'right' },
  { key: 'gross', label: 'document.gross', default: true, align: 'right' },
  { key: 'gross_huf', label: 'document.gross_huf', default: false, align: 'right' },
  { key: 'currency', label: 'common.currency', default: false },
  { key: 'payment_status', label: 'document.payment_col', default: false },
  { key: 'status', label: 'document.status_col', default: true },
]
