import { describe, expect, it } from 'vitest'
import { statusLabel } from './documentStatus'

// A valódi fordítások helyett a kulcsot magát adjuk vissza: a teszt a
// LEKÉPEZÉST méri (melyik display_status melyik fordítási kulcsot kapja),
// nem a TranslationSeeder tartalmát.
const t = (key) => `«${key}»`

describe('statusLabel', () => {
  it('a hat ismert display_status-t a megfelelő fordítási kulcsra képezi', () => {
    expect(statusLabel(t, 'paid')).toBe('«invoice.pay_paid»')
    expect(statusLabel(t, 'partially_paid')).toBe('«invoice.pay_partial»')
    expect(statusLabel(t, 'issued')).toBe('«invoice.st_issued»')
    expect(statusLabel(t, 'draft')).toBe('«invoice.st_draft»')
    expect(statusLabel(t, 'storno')).toBe('«invoice.st_storno»')
  })

  it('a "lejárt" az EGYETLEN saját document.* kulcs, a többi az invoice.* készletet használja újra', () => {
    expect(statusLabel(t, 'overdue')).toBe('«document.status_overdue»')
  })

  it('ismeretlen státuszra a nyers értéket adja vissza, nem üres címkét', () => {
    // A backend későbbi új display_status-a így legalább olvashatóan
    // megjelenik, nem tűnik el a chipből/szűrőből.
    expect(statusLabel(t, 'valami_uj')).toBe('valami_uj')
  })

  it('hiányzó státuszra sem dob, üres címkét ad vissza', () => {
    expect(statusLabel(t, undefined)).toBeUndefined()
    expect(statusLabel(t, '')).toBe('')
  })
})
