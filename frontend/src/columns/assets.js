// Eszközlista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const assetColumns = [
  { key: 'name', label: 'asset.name', default: true, locked: true },
  { key: 'serial_number', label: 'asset.serial_number', default: true },
  { key: 'imei', label: 'asset.imei', default: true },
  { key: 'asset_type', label: 'asset.asset_type', default: true },
  { key: 'status', label: 'asset.status', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
