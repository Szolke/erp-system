import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { TranslationProvider, useTranslation } from './TranslationContext'
import { translationsApi } from '../api/translations'

// A provider egyetlen külső függősége a fordítás-API — azt mockoljuk, a
// localStorage-ot viszont NEM: a jsdom valódi implementációját használjuk, mert
// a locale-perzisztencia éppen ezen a rétegen múlik (l. `storedLocale()`).
vi.mock('../api/translations', () => ({
  translationsApi: {
    forLocale: vi.fn(),
    setLocale: vi.fn(),
  },
}))

beforeEach(() => {
  localStorage.clear()
  translationsApi.forLocale.mockResolvedValue({ data: {} })
  translationsApi.setLocale.mockResolvedValue({})
})

afterEach(() => {
  vi.clearAllMocks()
})

function setup() {
  return renderHook(() => useTranslation(), { wrapper: TranslationProvider })
}

describe('TranslationContext — kezdeti nyelv', () => {
  it('mentett nyelv híján magyarra áll', () => {
    const { result } = setup()

    expect(result.current.locale).toBe('hu')
  })

  it('a localStorage-ban tárolt nyelvvel indul', () => {
    localStorage.setItem('locale', 'de')

    const { result } = setup()

    expect(result.current.locale).toBe('de')
  })

  it('ismeretlen tárolt nyelvet magyarra javít', () => {
    // Pl. kézzel átírt localStorage vagy egy időközben megszüntetett nyelv:
    // a felület soha ne induljon el nem támogatott locale-lal.
    localStorage.setItem('locale', 'fr')

    const { result } = setup()

    expect(result.current.locale).toBe('hu')
  })

  it('a támogatott nyelvek listája HU / EN / DE', () => {
    const { result } = setup()

    expect(result.current.SUPPORTED).toEqual(['hu', 'en', 'de'])
  })

  it('induláskor NEM tölt be szótárat magától', async () => {
    // A betöltést a bejelentkezési folyamat indítja (`loadLocale`), nem a
    // provider mountja — így a nyilvános oldalak nem hívják feleslegesen az API-t.
    setup()

    expect(translationsApi.forLocale).not.toHaveBeenCalled()
  })

  it('betöltés előtt a loaded jelző hamis', () => {
    const { result } = setup()

    expect(result.current.loaded).toBe(false)
  })
})

describe('TranslationContext — szótár betöltése', () => {
  it('betöltés után elérhetővé teszi a szótárat és jelzi a készenlétet', async () => {
    translationsApi.forLocale.mockResolvedValue({ data: { 'nav.documents': 'Bizonylatok' } })
    const { result } = setup()

    await act(async () => { await result.current.loadLocale('hu') })

    expect(translationsApi.forLocale).toHaveBeenCalledWith('hu')
    expect(result.current.strings).toEqual({ 'nav.documents': 'Bizonylatok' })
    expect(result.current.loaded).toBe(true)
  })

  it('hálózati hiba esetén NEM dob, csak üres szótárral folytat', async () => {
    // A felület enélkül fehér képernyőt adna, ha a fordítás-végpont elérhetetlen.
    // Az üres szótár mellett a `t()` a kulcsokat mutatja — csúnya, de működő UI.
    translationsApi.forLocale.mockRejectedValue(new Error('Network Error'))
    const { result } = setup()

    await act(async () => { await result.current.loadLocale('hu') })

    expect(result.current.strings).toEqual({})
  })

  it('hiba után is készre állítja a loaded jelzőt', async () => {
    // Ez a `finally` ág: ha a loaded hamis maradna, a rá váró felületek
    // örökre betöltés-állapotban ragadnának.
    translationsApi.forLocale.mockRejectedValue(new Error('Network Error'))
    const { result } = setup()

    await act(async () => { await result.current.loadLocale('hu') })

    expect(result.current.loaded).toBe(true)
  })
})

describe('TranslationContext — nyelvváltás', () => {
  it('elmenti a nyelvet a localStorage-ba és betölti a szótárát', async () => {
    const { result } = setup()

    await act(async () => { await result.current.setLocale('en') })

    expect(result.current.locale).toBe('en')
    expect(localStorage.getItem('locale')).toBe('en')
    expect(translationsApi.forLocale).toHaveBeenCalledWith('en')
  })

  it('nem támogatott nyelv kérésekor magyarra vált', async () => {
    const { result } = setup()

    await act(async () => { await result.current.setLocale('fr') })

    expect(result.current.locale).toBe('hu')
    expect(localStorage.getItem('locale')).toBe('hu')
    expect(translationsApi.forLocale).toHaveBeenCalledWith('hu')
  })

  it('alapértelmezésben NEM menti a szerverre a választást', async () => {
    // A nyelvváltó gomb csak a böngészőben állít; a felhasználó profiljába
    // mentés külön, explicit kérésre történik.
    const { result } = setup()

    await act(async () => { await result.current.setLocale('en') })

    expect(translationsApi.setLocale).not.toHaveBeenCalled()
  })

  it('kérésre a szerverre is elmenti a választást', async () => {
    const { result } = setup()

    await act(async () => { await result.current.setLocale('en', true) })

    expect(translationsApi.setLocale).toHaveBeenCalledWith('en')
  })

  it('a szerverre mentés hibája nem rontja el a helyi nyelvváltást', async () => {
    // A locale a képernyőn már átváltott; egy sikertelen profil-mentés miatt
    // nem szabad visszaugrania vagy kivételt dobnia.
    translationsApi.setLocale.mockRejectedValue(new Error('500'))
    const { result } = setup()

    await act(async () => { await result.current.setLocale('de', true) })

    expect(result.current.locale).toBe('de')
    expect(localStorage.getItem('locale')).toBe('de')
  })

  it('nyelvváltáskor lecseréli a szótárat, nem összefésüli', async () => {
    // Összefésülés esetén az előző nyelv kulcsai átszivárognának — kevert
    // nyelvű felületet okozva ott, ahol az új szótárból hiányzik egy kulcs.
    translationsApi.forLocale.mockResolvedValueOnce({ data: { 'a.b': 'Magyar', 'csak.hu': 'Csak itt' } })
    const { result } = setup()
    await act(async () => { await result.current.setLocale('hu') })

    translationsApi.forLocale.mockResolvedValueOnce({ data: { 'a.b': 'English' } })
    await act(async () => { await result.current.setLocale('en') })

    expect(result.current.strings).toEqual({ 'a.b': 'English' })
    expect(result.current.t('csak.hu')).toBe('csak.hu')
  })
})

describe('TranslationContext — t() feloldás', () => {
  async function withStrings(strings) {
    translationsApi.forLocale.mockResolvedValue({ data: strings })
    const { result } = setup()
    await act(async () => { await result.current.loadLocale('hu') })
    return result
  }

  it('a kulcshoz tartozó fordítást adja vissza', async () => {
    const result = await withStrings({ 'nav.documents': 'Bizonylatok' })

    expect(result.current.t('nav.documents')).toBe('Bizonylatok')
  })

  it('ismeretlen kulcs esetén MAGÁT A KULCSOT adja vissza', async () => {
    // Szándékos: így a hiányzó fordítás azonnal látszik a felületen, nem
    // üres helyre fut ki.
    const result = await withStrings({})

    expect(result.current.t('nav.missing')).toBe('nav.missing')
  })

  it('behelyettesíti a paramétereket', async () => {
    const result = await withStrings({ 'list.total': 'Összesen {count} sor' })

    expect(result.current.t('list.total', { count: 42 })).toBe('Összesen 42 sor')
  })

  it('több paramétert is behelyettesít', async () => {
    const result = await withStrings({ 'page.of': '{current} / {total} oldal' })

    expect(result.current.t('page.of', { current: 2, total: 7 })).toBe('2 / 7 oldal')
  })

  it('a nem szöveges paramétert szöveggé alakítja', async () => {
    const result = await withStrings({ 'x': 'érték: {v}' })

    expect(result.current.t('x', { v: 0 })).toBe('érték: 0')
    expect(result.current.t('x', { v: false })).toBe('érték: false')
  })

  it('a meg nem adott paraméter helyőrzője bennmarad', async () => {
    // Nem hiba-elnyelés: a felületen látszó `{count}` jelzi, hogy a hívó
    // elfelejtett paramétert adni.
    const result = await withStrings({ 'list.total': 'Összesen {count} sor' })

    expect(result.current.t('list.total')).toBe('Összesen {count} sor')
  })

  it('ismeretlen kulcsnál is elvégzi a behelyettesítést, ha a kulcs tartalmaz helyőrzőt', async () => {
    const result = await withStrings({})

    expect(result.current.t('x.{v}', { v: 1 })).toBe('x.1')
  })
})

describe('TranslationContext — provider nélküli használat', () => {
  it('a useTranslation provider nélkül beszédes hibát dob', () => {
    // A néma `undefined` destrukturálás-hiba helyett azonnal megnevezi az okot.
    expect(() => renderHook(() => useTranslation())).toThrow(
      /useTranslation must be used within TranslationProvider/,
    )
  })
})
