import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  prevMonthRange,
  prevYearRange,
  thisMonthRange,
  thisYearRange,
  todayStr,
} from './reportPeriods'

// Mind az öt függvény `new Date()`-re épül, ezért rögzített rendszeridővel
// tesztelünk. A `new Date(év, hó, nap, …)` konstruktor HELYI időt vesz, ahogy a
// modul is a lokális gettereket olvassa — így a teszt időzóna-független.

function freezeAt(year, monthIndex, day) {
  vi.setSystemTime(new Date(year, monthIndex, day, 12, 0, 0))
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('thisMonthRange', () => {
  it('az aktuális hónapot adja from-ként és to-ként is', () => {
    freezeAt(2026, 6, 30) // 2026-07-30
    expect(thisMonthRange()).toEqual({ from: '2026-07', to: '2026-07' })
  })

  it('egyjegyű hónapot nullával tölt fel (a backend YYYY-MM alakot vár)', () => {
    freezeAt(2026, 2, 9) // 2026-03-09
    expect(thisMonthRange()).toEqual({ from: '2026-03', to: '2026-03' })
  })
})

describe('prevMonthRange', () => {
  it('az előző hónapot adja éven belül', () => {
    freezeAt(2026, 6, 30) // 2026-07-30
    expect(prevMonthRange()).toEqual({ from: '2026-06', to: '2026-06' })
  })

  it('januárban átlép az előző ÉV decemberére', () => {
    // Ez a csendes hibák klasszikus helye: év-forduló nélkül "2026-00" jönne ki.
    freezeAt(2026, 0, 15) // 2026-01-15
    expect(prevMonthRange()).toEqual({ from: '2025-12', to: '2025-12' })
  })

  it('a hónap 31-én sem csordul túl rövidebb hónapra', () => {
    // A modul azért ad 1-et napként a Date konstruktornak, mert a `31` nap egy
    // 28/30 napos előző hónapban túlcsordulna a rákövetkező hónapra
    // (2026-03-31 → "február 31" → március 3.), és a range egy hónapot ugrana.
    freezeAt(2026, 2, 31) // 2026-03-31
    expect(prevMonthRange()).toEqual({ from: '2026-02', to: '2026-02' })
  })
})

describe('thisYearRange', () => {
  it('januártól az AKTUÁLIS hónapig tart, nem az év végéig', () => {
    freezeAt(2026, 6, 30) // 2026-07-30
    expect(thisYearRange()).toEqual({ from: '2026-01', to: '2026-07' })
  })

  it('januárban egyetlen hónapra szűkül', () => {
    freezeAt(2026, 0, 15)
    expect(thisYearRange()).toEqual({ from: '2026-01', to: '2026-01' })
  })
})

describe('prevYearRange', () => {
  it('a teljes előző évet adja, az aktuális hónaptól függetlenül', () => {
    freezeAt(2026, 6, 30)
    expect(prevYearRange()).toEqual({ from: '2025-01', to: '2025-12' })
  })
})

describe('todayStr', () => {
  it('YYYY-MM-DD alakot ad', () => {
    freezeAt(2026, 6, 30)
    expect(todayStr()).toBe('2026-07-30')
  })

  it('egyjegyű hónapot ÉS napot is nullával tölt fel', () => {
    freezeAt(2026, 0, 5) // 2026-01-05
    expect(todayStr()).toBe('2026-01-05')
  })
})
