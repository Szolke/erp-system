// A beépített kézikönyv-nézegető tartalma közvetlenül a `docs/wiki` markdown
// fájljaiból származik: a `@wiki` alias erre a könyvtárra mutat (a konténerben
// bind-mounttal, l. compose.yaml: `./docs/wiki:/app/wiki`), a fájlok pedig
// build-időben, nyersen (`?raw`) kerülnek a bundle-be. Nincs külön becsomagolt
// másolat, tehát a nézegető és a repóban lévő kézikönyv nem tud tartalmilag
// szétcsúszni.
//
// A betöltés SZÁNDÉKOSAN glob-alapú. A korábbi megoldás fejezetenként egy-egy
// statikus importot és egy `PAGE_COUNT = 8` konstanst tartalmazott — emiatt a
// 09/10/11 fejezet csendben kimaradt a nézegetőből, holott a fájlok már ott
// voltak a repóban. Globbal egy új oldalpár felvételéhez ehhez a fájlhoz nem
// kell hozzányúlni; a szinkront a mellette lévő index.test.js őrzi.
const huModules = import.meta.glob('@wiki/hu/*.md', { query: '?raw', import: 'default', eager: true })
const enModules = import.meta.glob('@wiki/en/*.md', { query: '?raw', import: 'default', eager: true })

// A wiki konvenciója szerint a HU és EN oldalak PÁRJÁT kizárólag a kétjegyű
// sorszám-előtag köti össze (a slug fordított: `11-eszkozok.md` ↔ `11-assets.md`).
const CHAPTER_FILE_RE = /(\d{2})-[^/]*\.md$/

// A glob kulcs-sorrendje nem része a szerződésnek, ezért a fejezeteket a
// sorszám-előtag alapján rendezzük. A mintára nem illeszkedő fájlok (pl. egy
// odakerülő README) kimaradnak — nem fejezetek.
function collectChapters(modules) {
  return Object.entries(modules)
    .map(([path, content]) => {
      const match = path.match(CHAPTER_FILE_RE)
      return match ? { number: Number(match[1]), content } : null
    })
    .filter(Boolean)
    .sort((a, b) => a.number - b.number)
}

const chapters = {
  hu: collectChapters(huModules),
  en: collectChapters(enModules),
}

const pages = {
  hu: chapters.hu.map((c) => c.content),
  en: chapters.en.map((c) => c.content),
}

// Fejezetszám → 0-alapú index a `pages` tömbben. Nem `sorszám - 1`, mert egy
// esetleges számozási lyuk így sem tolná el a hivatkozásokat.
const numberToIndex = {
  hu: new Map(chapters.hu.map((c, i) => [c.number, i])),
  en: new Map(chapters.en.map((c, i) => [c.number, i])),
}

// A magyar a forrásnyelv, ezért az oldalszám onnan jön. A HU/EN darabszám-
// paritást az index.test.js ellenőrzi — enélkül egy hiányzó fordítás helyén a
// getPage() csendben az 1. fejezetre esne vissza.
export const PAGE_COUNT = pages.hu.length

// Az elérhető fejezetszámok (a nézegetőn kívüli ellenőrzésekhez, l. index.test.js)
export const CHAPTER_NUMBERS = {
  hu: chapters.hu.map((c) => c.number),
  en: chapters.en.map((c) => c.number),
}

export function getPage(locale, index) {
  const localePages = pages[locale] ?? pages.hu
  return localePages[index] ?? localePages[0]
}

// Extracts the H1 title from markdown content
export function getTitle(content) {
  const match = content.match(/^# (.+)$/m)
  return match ? match[1] : ''
}

// Given an href like "03-nyugta-kiallitas.md", returns the 0-based chapter index or -1
export function hrefToChapterIndex(href, locale) {
  const map = numberToIndex[locale] ?? numberToIndex.hu
  const filename = href.replace(/^.*\//, '')
  const match = filename.match(/^(\d{2})-/)
  if (!match) return -1
  const index = map.get(Number(match[1]))
  return index === undefined ? -1 : index
}
