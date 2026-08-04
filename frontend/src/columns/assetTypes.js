// Eszköztípus-lista oszlop-regisztere — a useListColumns hook ebből olvassa
// ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mind a 3 oszlop rendezhető; a kulcsok 1:1-ben megfelelnek a
// backend whitelistjének (AssetTypeController::SORTABLE_COLUMNS) — a `scope`
// ott a `(company_id IS NULL)` kifejezésre képződik le, tehát a saját és a
// globális típusok csoportosítva jönnek.
export const assetTypeColumns = [
  { key: 'code', label: 'asset_type.code', default: true, locked: true, sortable: true },
  { key: 'name', label: 'asset_type.name', default: true, sortable: true },
  { key: 'scope', label: 'asset_type.scope', default: true, sortable: true },
]
