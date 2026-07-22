// Egyéni mezők lista oszlop-regisztere — a useListColumns hook ebből olvassa
// ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
export const customFieldColumns = [
  { key: 'key', label: 'customfield.key', default: true, locked: true },
  { key: 'label', label: 'customfield.label', default: true },
  { key: 'type', label: 'customfield.type', default: true },
  { key: 'required', label: 'customfield.required', default: true },
  { key: 'active', label: 'common.active', default: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
