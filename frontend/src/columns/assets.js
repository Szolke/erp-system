// Eszközlista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mind az 5 adatoszlop rendezhető; a kulcsok 1:1-ben megfelelnek a
// backend whitelistjének (AssetController::SORTABLE_COLUMNS) — az `asset_type`
// ott a kapcsolt `asset_types.name`-re képződik le (leftJoin). Az `actions`
// oszlop nem adat, ezért nem rendezhető.
export const assetColumns = [
  { key: 'name', label: 'asset.name', default: true, locked: true, sortable: true },
  { key: 'serial_number', label: 'asset.serial_number', default: true, sortable: true },
  { key: 'imei', label: 'asset.imei', default: true, sortable: true },
  { key: 'asset_type', label: 'asset.asset_type', default: true, sortable: true },
  { key: 'status', label: 'asset.status', default: true, sortable: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
