// Terméklista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mind a 6 adatoszlop rendezhető; a kulcsok 1:1-ben megfelelnek a
// backend whitelistjének (ProductController::SORTABLE_COLUMNS) — a `vat_rate`
// ott a kapcsolt `vat_rates.name`-re képződik le (leftJoin). Az `actions`
// oszlop nem adat, ezért nem rendezhető. A whitelist ettől FÜGGETLEN védelem: a
// szerver akkor sem rendez ismeretlen oszlopra, ha ez a lista elromlana.
//
// `sortInitialDir: 'desc'`: az első kattintás iránya. Összegnél a "legnagyobb
// elöl" a várt elsődleges nézet, szövegnél az ábécésorrend — ezért a mező csak
// ott szerepel, ahol az alapértelmezés (asc) nem a természetes olvasat.
export const productColumns = [
  { key: 'sku', label: 'product.sku', default: true, locked: true, sortable: true },
  { key: 'name', label: 'product.name', default: true, sortable: true },
  { key: 'unit', label: 'product.unit', default: true, sortable: true },
  { key: 'base_price', label: 'product.base_price', default: true, align: 'right', sortable: true, sortInitialDir: 'desc' },
  { key: 'vat_rate', label: 'product.vat_rate', default: true, sortable: true },
  { key: 'type', label: 'product.type_col', default: true, sortable: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
