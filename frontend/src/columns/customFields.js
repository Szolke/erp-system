// Egyéni mezők lista oszlop-regisztere — a useListColumns hook ebből olvassa
// ki, mely oszlopok léteznek. A `label` egy t()-fordítási kulcs.
//
// `sortable`: mind az 5 adatoszlop rendezhető; a kulcsok megfelelnek a backend
// whitelistjének (CustomFieldDefinitionController::SORTABLE_COLUMNS) — a
// `required`/`active` ott az `is_required`/`is_active` oszlopra képződik le. A
// backend whitelistjében szerepel egy `sort_order` kulcs is, de ennek a listának
// nincs `sort_order` OSZLOPA (a mező csak a szerkesztő űrlapon jelenik meg),
// ezért itt nem szerepel — a whitelist bővebb lehet, mint a fejlécek halmaza.
// Az `actions` oszlop nem adat, ezért nem rendezhető.
export const customFieldColumns = [
  { key: 'key', label: 'customfield.key', default: true, locked: true, sortable: true },
  { key: 'label', label: 'customfield.label', default: true, sortable: true },
  { key: 'type', label: 'customfield.type', default: true, sortable: true },
  { key: 'required', label: 'customfield.required', default: true, sortable: true },
  { key: 'active', label: 'common.active', default: true, sortable: true },
  { key: 'actions', label: 'common.actions', default: true, locked: true },
]
