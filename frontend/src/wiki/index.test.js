import { readdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { CHAPTER_NUMBERS, PAGE_COUNT, getPage, getTitle, hrefToChapterIndex } from './index.js'

// ── Drift-őr ─────────────────────────────────────────────────────────────────
//
// Ez a fájl a beépített nézegető és a repóban lévő kézikönyv ELCSÚSZÁSÁT fogja
// meg. Volt rá precedens: az index.js sokáig 8 oldalt csomagolt (statikus
// import-lista + `PAGE_COUNT = 8`), miközben a docs/wiki már 11 oldalpárt
// tartalmazott — a 09/10/11 fejezet hónapokig láthatatlan volt a felületen,
// és semmi nem jelezte.
//
// A tesztek a VALÓDI fájlrendszert olvassák: a `@wiki` alias a `docs/wiki`
// könyvtárra mutat, amit a compose a konténerbe `/app/wiki`-ként csatol
// (`./docs/wiki:/app/wiki`). A suite kizárólag a konténerben fut
// (`docker compose exec frontend npm run test`) — a hoston a `frontend/wiki`
// csak egy üres mount-pont, ott a lenti "nem üres" ellenőrzés bukna el.
//
// A könyvtárat a projekt-gyökérből vezetjük le, ugyanúgy, ahogy a vite.config.js
// az aliast (`./wiki`) — `import.meta.url` jsdom környezetben nem `file:`
// sémájú, abból nem képezhető fájlrendszer-útvonal.
const WIKI_DIR = resolve(process.cwd(), 'wiki')

function chapterFilesOnDisk(locale) {
  return readdirSync(`${WIKI_DIR}/${locale}`)
    .filter((name) => /^\d{2}-.*\.md$/.test(name))
    .sort()
}

function chapterNumbersOnDisk(locale) {
  return chapterFilesOnDisk(locale).map((name) => Number(name.slice(0, 2)))
}

describe('wiki — a becsomagolt oldalak és a docs/wiki szinkronja', () => {
  it('a docs/wiki bind-mount elérhető és nem üres (különben a suite nem a konténerben fut)', () => {
    expect(
      chapterFilesOnDisk('hu').length,
      `nem található fejezet-fájl itt: ${WIKI_DIR}/hu — a suite-ot a konténerben kell futtatni: docker compose exec frontend npm run test`,
    ).toBeGreaterThan(0)
  })

  it('minden lemezen lévő magyar fejezet be van csomagolva (a 09/10/11 kimaradását fogja)', () => {
    const onDisk = chapterNumbersOnDisk('hu')
    expect(
      CHAPTER_NUMBERS.hu,
      `a becsomagolt fejezetek eltérnek a docs/wiki/hu tartalmától — becsomagolva: [${CHAPTER_NUMBERS.hu}], lemezen: [${onDisk}]`,
    ).toEqual(onDisk)
  })

  it('minden lemezen lévő angol fejezet be van csomagolva', () => {
    const onDisk = chapterNumbersOnDisk('en')
    expect(
      CHAPTER_NUMBERS.en,
      `a becsomagolt fejezetek eltérnek a docs/wiki/en tartalmától — becsomagolva: [${CHAPTER_NUMBERS.en}], lemezen: [${onDisk}]`,
    ).toEqual(onDisk)
  })

  it('a HU és EN fejezetszámok megegyeznek (hiányzó fordítás esetén a getPage csendben az 1. fejezetre esne vissza)', () => {
    expect(
      CHAPTER_NUMBERS.en,
      `HU/EN paritás sérült — HU: [${CHAPTER_NUMBERS.hu}], EN: [${CHAPTER_NUMBERS.en}]`,
    ).toEqual(CHAPTER_NUMBERS.hu)
  })

  it('a fejezetszámozás 1-től hézagmentesen folytonos', () => {
    const expected = CHAPTER_NUMBERS.hu.map((_, i) => i + 1)
    expect(CHAPTER_NUMBERS.hu, `hiányzó vagy duplikált sorszám: [${CHAPTER_NUMBERS.hu}]`).toEqual(expected)
  })

  it('a PAGE_COUNT a tényleges fejezetszámot adja (nem hardcode-olt konstans)', () => {
    expect(PAGE_COUNT).toBe(chapterNumbersOnDisk('hu').length)
  })
})

describe('wiki — oldal-tartalom', () => {
  it('minden fejezetnek van H1 címe mindkét nyelven', () => {
    for (let i = 0; i < PAGE_COUNT; i += 1) {
      expect(getTitle(getPage('hu', i)), `hu/${i + 1}. fejezet: hiányzó H1 cím`).not.toBe('')
      expect(getTitle(getPage('en', i)), `en/${i + 1}. fejezet: hiányzó H1 cím`).not.toBe('')
    }
  })

  it('a fejezetek tartalma nyelvenként különbözik (nem ugyanaz a fájl került be kétszer)', () => {
    for (let i = 0; i < PAGE_COUNT; i += 1) {
      expect(getPage('en', i), `${i + 1}. fejezet: a HU és EN tartalom azonos`).not.toBe(getPage('hu', i))
    }
  })

  it('ismeretlen nyelvre és tartományon kívüli indexre is ad tartalmat', () => {
    expect(getPage('de', 0)).toBe(getPage('hu', 0))
    expect(getPage('hu', PAGE_COUNT + 5)).toBe(getPage('hu', 0))
  })
})

describe('wiki — belső hivatkozás feloldása (hrefToChapterIndex)', () => {
  it('a sorszám-előtag alapján oldja fel a fejezetet, könyvtár-előtaggal is', () => {
    expect(hrefToChapterIndex('03-nyugta-kiallitas.md', 'hu')).toBe(2)
    expect(hrefToChapterIndex('hu/03-nyugta-kiallitas.md', 'hu')).toBe(2)
    expect(hrefToChapterIndex('03-issuing-receipt.md', 'en')).toBe(2)
  })

  it('a legutóbb hozzáadott fejezetre mutató hivatkozás is feloldódik (a 06 → 11 kereszthivatkozás)', () => {
    const last = CHAPTER_NUMBERS.hu.length
    const padded = String(last).padStart(2, '0')
    expect(hrefToChapterIndex(`${padded}-eszkozok.md`, 'hu')).toBe(last - 1)
    expect(hrefToChapterIndex(`${padded}-assets.md`, 'en')).toBe(last - 1)
  })

  it('nem fejezet-hivatkozásra -1-et ad', () => {
    expect(hrefToChapterIndex('../README.md', 'hu')).toBe(-1)
    expect(hrefToChapterIndex('../progress.md', 'hu')).toBe(-1)
    expect(hrefToChapterIndex('#szakasz', 'hu')).toBe(-1)
  })

  it('nem létező fejezetszámra -1-et ad', () => {
    expect(hrefToChapterIndex('99-nincs-ilyen.md', 'hu')).toBe(-1)
  })

  it('ismeretlen nyelv esetén a magyar térképre esik vissza', () => {
    expect(hrefToChapterIndex('04-bizonylatok.md', 'de')).toBe(3)
  })
})
