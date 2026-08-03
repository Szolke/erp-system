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
//
// `sortable`: mind a 14 oszlop rendezhető — mindegyik egy valódi oszlopra képződik
// le az egyesített (union) lekérdezésben, l. DocumentController::SORTABLE_COLUMNS
// (a backend whitelistje ezzel 1:1-ben áll, de attól FÜGGETLEN védelem: a
// szerver akkor sem rendez ismeretlen oszlopra, ha ez a lista elromlana).
//
// `sortInitialDir: 'desc'`: az első kattintás iránya. Dátumnál és összegnél a
// "legfrissebb/legnagyobb elöl" a várt elsődleges nézet, szövegnél viszont az
// ábécésorrend — ezért a mező csak ott szerepel, ahol az alapértelmezés (asc)
// nem a természetes olvasat.
export const documentColumns = [
  { key: 'number', label: 'document.number', default: true, locked: true, sortable: true },
  { key: 'type', label: 'document.type_col', default: false, sortable: true },
  { key: 'partner', label: 'document.partner', default: true, sortable: true },
  { key: 'partner_tax_number', label: 'document.partner_tax_number', default: false, sortable: true },
  { key: 'issue_date', label: 'document.issued_at', default: true, sortable: true, sortInitialDir: 'desc' },
  { key: 'fulfillment_date', label: 'document.fulfillment_date', default: false, sortable: true, sortInitialDir: 'desc' },
  { key: 'due_date', label: 'document.due_date_col', default: false, sortable: true, sortInitialDir: 'desc' },
  { key: 'net_total', label: 'document.net', default: false, align: 'right', sortable: true, sortInitialDir: 'desc' },
  { key: 'vat_total', label: 'document.vat', default: false, align: 'right', sortable: true, sortInitialDir: 'desc' },
  { key: 'gross', label: 'document.gross', default: true, align: 'right', sortable: true, sortInitialDir: 'desc' },
  { key: 'gross_huf', label: 'document.gross_huf', default: false, align: 'right', sortable: true, sortInitialDir: 'desc' },
  { key: 'currency', label: 'common.currency', default: false, sortable: true },
  { key: 'payment_status', label: 'document.payment_col', default: false, sortable: true },
  { key: 'status', label: 'document.status_col', default: true, sortable: true },
]
