import hu01 from '@wiki/hu/01-attekintes-bejelentkezes.md?raw'
import hu02 from '@wiki/hu/02-szamla-kiallitas.md?raw'
import hu03 from '@wiki/hu/03-nyugta-kiallitas.md?raw'
import hu04 from '@wiki/hu/04-bizonylatok.md?raw'
import hu05 from '@wiki/hu/05-fizetesek.md?raw'
import hu06 from '@wiki/hu/06-beallitasok.md?raw'
import hu07 from '@wiki/hu/07-felhasznalok-jogosultsagok.md?raw'
import hu08 from '@wiki/hu/08-egyeb.md?raw'

import en01 from '@wiki/en/01-overview-login.md?raw'
import en02 from '@wiki/en/02-issuing-invoice.md?raw'
import en03 from '@wiki/en/03-issuing-receipt.md?raw'
import en04 from '@wiki/en/04-documents.md?raw'
import en05 from '@wiki/en/05-payments.md?raw'
import en06 from '@wiki/en/06-settings.md?raw'
import en07 from '@wiki/en/07-users-permissions.md?raw'
import en08 from '@wiki/en/08-other.md?raw'

const pages = {
  hu: [hu01, hu02, hu03, hu04, hu05, hu06, hu07, hu08],
  en: [en01, en02, en03, en04, en05, en06, en07, en08],
}

export const PAGE_COUNT = 8

export function getPage(locale, index) {
  const localePages = pages[locale] ?? pages.hu
  return localePages[index] ?? localePages[0]
}

// Extracts the H1 title from markdown content
export function getTitle(content) {
  const match = content.match(/^# (.+)$/m)
  return match ? match[1] : ''
}

// Returns chapter slugs to match internal wiki links (e.g. "03-nyugta-kiallitas.md")
const CHAPTER_FILENAME_PREFIXES = {
  hu: [
    '01-attekintes', '02-szamla', '03-nyugta', '04-bizonylatok',
    '05-fizetesek', '06-beallitasok', '07-felhasznalok', '08-egyeb',
  ],
  en: [
    '01-overview', '02-issuing-invoice', '03-issuing-receipt', '04-documents',
    '05-payments', '06-settings', '07-users', '08-other',
  ],
}

// Given an href like "03-nyugta-kiallitas.md", returns the 0-based chapter index or -1
export function hrefToChapterIndex(href, locale) {
  const prefixes = CHAPTER_FILENAME_PREFIXES[locale] ?? CHAPTER_FILENAME_PREFIXES.hu
  const clean = href.replace(/\.md$/, '').replace(/^.*\//, '')
  return prefixes.findIndex((p) => clean.startsWith(p))
}
