// Eszköztípus-lista oszlop-regisztere — a useListColumns hook ebből olvassa
// ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const assetTypeColumns = [
  { key: 'code', label: 'asset_type.code', default: true, locked: true },
  { key: 'name', label: 'asset_type.name', default: true },
  { key: 'scope', label: 'asset_type.scope', default: true },
]
