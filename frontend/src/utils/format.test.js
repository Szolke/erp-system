import { describe, expect, it } from 'vitest'
import { formatCurrency, formatDate, formatDateTime, formatTimestamp } from './format'

// A locale-függő assertek szándékosan `en`-nel futnak: a `hu` csoportosítás/
// elválasztás ICU-verziónként változhat (keskeny nem törő szóköz stb.), az `en`
// kimenet viszont stabil. Amit itt mérünk, az a függvény SAJÁT logikája
// (normalizálás, fallback, üres/érvénytelen kezelés), nem az Intl formázása.

describe('formatCurrency', () => {
  it('a számot és a vele azonos értékű szám-STRINGET ugyanúgy formázza', () => {
    // Nyitott pontok #16: a backend a pénz-mezőt számként ÉS numerikus
    // stringként is küldheti — a kettő nem térhet el a felületen.
    expect(formatCurrency(1234.5, 'HUF', 'en')).toBe('1,234.5 HUF')
    expect(formatCurrency('1234.5', 'HUF', 'en')).toBe('1,234.5 HUF')
  })

  it('pénznem nélkül csak a formázott számot adja, záró szóköz nélkül', () => {
    expect(formatCurrency(1000, null, 'en')).toBe('1,000')
    expect(formatCurrency(1000, '', 'en')).toBe('1,000')
    expect(formatCurrency(1000, undefined, 'en')).toBe('1,000')
  })

  it('nem szám értéket változatlanul ad vissza (a Number.isFinite kapu), nem NaN-t ír ki', () => {
    expect(formatCurrency('nincs adat', 'HUF', 'en')).toBe('nincs adat HUF')
    expect(formatCurrency('nincs adat', null, 'en')).toBe('nincs adat')
  })

  it('a nullát értékként kezeli, nem üresként', () => {
    expect(formatCurrency(0, 'HUF', 'en')).toBe('0 HUF')
    expect(formatCurrency('0', 'HUF', 'en')).toBe('0 HUF')
  })
})

describe('formatDate', () => {
  it('üres bemenetre üres stringet ad (nem "Invalid Date"-et)', () => {
    expect(formatDate('')).toBe('')
    expect(formatDate(null)).toBe('')
    expect(formatDate(undefined)).toBe('')
  })

  it('érvénytelen dátum-stringet változatlanul enged át', () => {
    expect(formatDate('nem-dátum')).toBe('nem-dátum')
  })

  it('érvényes ISO-időbélyeget a locale dátum-alakjára formáz', () => {
    // Z nélküli alak: a Date HELYI időként értelmezi, így a teszt nem függ a
    // futtató időzónájától.
    expect(formatDate('2026-07-28T19:34:01', 'en')).toBe('7/28/2026')
  })
})

describe('formatDateTime', () => {
  it('üres bemenetre üres stringet, érvénytelenre változatlan bemenetet ad', () => {
    expect(formatDateTime('')).toBe('')
    expect(formatDateTime(null)).toBe('')
    expect(formatDateTime('nem-dátum')).toBe('nem-dátum')
  })

  it('a dátumot ÉS az időt is tartalmazza (a formatDate-tel szemben)', () => {
    // Regexszel, nem pontos egyezéssel: az AM/PM elé az újabb ICU-k keskeny
    // nem törő szóközt tesznek, ami verziónként eltér — a lényeg, hogy az
    // időrész egyáltalán megjelenik.
    const result = formatDateTime('2026-07-28T19:34:01', 'en')
    expect(result).toMatch(/^7\/28\/2026/)
    expect(result).toMatch(/7:34:01/)
  })
})

describe('formatTimestamp', () => {
  it('üres bemenetre üres stringet, érvénytelenre változatlan bemenetet ad', () => {
    expect(formatTimestamp('')).toBe('')
    expect(formatTimestamp(null)).toBe('')
    expect(formatTimestamp('nem-dátum')).toBe('nem-dátum')
  })

  it('locale-független "YYYY-MM-DD HH:mm" alakot ad', () => {
    expect(formatTimestamp('2026-07-28T19:34:01')).toBe('2026-07-28 19:34')
  })

  it('egyjegyű hónapot/napot/órát/percet nullával tölt fel', () => {
    // A pad() nélkül "2026-1-5 8:7" lenne — a blame-lábléc rendezhetőségét
    // pont a fix szélesség adja.
    expect(formatTimestamp('2026-01-05T08:07:00')).toBe('2026-01-05 08:07')
  })

  it('a másodperceket elhagyja', () => {
    expect(formatTimestamp('2026-12-31T23:59:59')).toBe('2026-12-31 23:59')
  })
})
