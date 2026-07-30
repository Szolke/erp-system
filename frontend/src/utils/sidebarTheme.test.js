import { beforeEach, describe, expect, it } from 'vitest'
import { applySidebarTheme } from './sidebarTheme'

// A függvény a :root CSS-változóit írja, ezért a jsdom document-en keresztül
// ellenőrizzük. A luminancia-küszöb (WCAG) és a hover-keverés két külön ága
// vizuálisan nehezen szúrható ki — a kontraszt-regresszió pont az a hiba,
// amit automatizált teszt fog meg olcsón.

function cssVar(name) {
  return document.documentElement.style.getPropertyValue(name)
}

beforeEach(() => {
  const root = document.documentElement
  root.style.removeProperty('--sidebar-accent-color')
  root.style.removeProperty('--sidebar-text-color')
  root.style.removeProperty('--sidebar-hover-bg')
})

describe('applySidebarTheme — akcentus-szín', () => {
  it('az átadott hexet változatlanul teszi ki akcentus-változóként', () => {
    applySidebarTheme('#1e40af')
    expect(cssVar('--sidebar-accent-color')).toBe('#1e40af')
  })

  it('mindhárom sidebar-változót beállítja', () => {
    applySidebarTheme('#1e40af')
    expect(cssVar('--sidebar-accent-color')).not.toBe('')
    expect(cssVar('--sidebar-text-color')).not.toBe('')
    expect(cssVar('--sidebar-hover-bg')).not.toBe('')
  })
})

describe('applySidebarTheme — szövegszín (WCAG-küszöb: L < 0.179)', () => {
  it('sötét háttéren fehér szöveget ad', () => {
    applySidebarTheme('#000000')
    expect(cssVar('--sidebar-text-color')).toBe('#ffffff')
  })

  it('a projekt alapértelmezett indigó akcentusa is a sötét ághoz tartozik', () => {
    // #1e40af relatív luminanciája ~0.070 — bőven a 0.179-es küszöb alatt.
    applySidebarTheme('#1e40af')
    expect(cssVar('--sidebar-text-color')).toBe('#ffffff')
  })

  it('világos háttéren sötét (#1e293b) szöveget ad', () => {
    applySidebarTheme('#ffffff')
    expect(cssVar('--sidebar-text-color')).toBe('#1e293b')
  })

  it('élénk sárgán is a sötét szöveg nyer (a nyers RGB-átlag itt tévedne)', () => {
    // #fde047: a zöld csatorna súlya (0.7152) miatt a luminancia magas, holott
    // egy naiv (r+g+b)/3 küszöb közel esne a határhoz.
    applySidebarTheme('#fde047')
    expect(cssVar('--sidebar-text-color')).toBe('#1e293b')
  })
})

describe('applySidebarTheme — hover-háttér (két külön ág)', () => {
  it('nagyon sötét alapot (L < 0.05) VILÁGOSÍT, nem sötétít tovább', () => {
    // Feketén a sötétítés láthatatlan maradna: 85% fekete + 15% fehér.
    applySidebarTheme('#000000')
    expect(cssVar('--sidebar-hover-bg')).toBe('#262626')
  })

  it('minden más alapot SÖTÉTÍT (85% alap + 15% fekete)', () => {
    applySidebarTheme('#1e40af')
    expect(cssVar('--sidebar-hover-bg')).toBe('#1a3695')
  })

  it('fehér alapon a sötétítés látható szürkét ad', () => {
    applySidebarTheme('#ffffff')
    expect(cssVar('--sidebar-hover-bg')).toBe('#d9d9d9')
  })

  it('a 16 alatti csatorna-értéket két számjegyre tölti fel', () => {
    // #00ff00: a nulla csatornákból padStart nélkül "#0d900" jönne ki —
    // érvénytelen hex, a CSS csendben eldobná a hover-színt.
    applySidebarTheme('#00ff00')
    expect(cssVar('--sidebar-hover-bg')).toBe('#00d900')
  })
})
