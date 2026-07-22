// Terméklista oszlop-regisztere — a useListColumns hook ebből olvassa ki,
// mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const productColumns = [
  { key: 'sku', label: 'product.sku', default: true, locked: true },
  { key: 'name', label: 'product.name', default: true },
  { key: 'unit', label: 'product.unit', default: true },
  { key: 'base_price', label: 'product.base_price', default: true, align: 'right' },
  { key: 'vat_rate', label: 'product.vat_rate', default: true },
  { key: 'type', label: 'product.type_col', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
