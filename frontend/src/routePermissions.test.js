import { describe, expect, it } from 'vitest'
import { ROUTE_ACCESS, hasRouteAccess } from './routePermissions'

const KNOWN_SHAPES = ['public', 'perm', 'anyPerm', 'superadminOnly', 'check']

describe('ROUTE_ACCESS — strukturális ellenőrzés', () => {
  it('minden route-hoz pontosan egy felismert követelmény-forma tartozik (elgépelt/ismeretlen kulcsot fog)', () => {
    for (const [path, access] of Object.entries(ROUTE_ACCESS)) {
      const usedShapes = Object.keys(access).filter((k) => KNOWN_SHAPES.includes(k))
      const unknownShapes = Object.keys(access).filter((k) => !KNOWN_SHAPES.includes(k))

      expect(unknownShapes, `${path}: ismeretlen kulcs(ok) a követelményben: ${unknownShapes.join(', ')}`).toEqual([])
      expect(usedShapes.length, `${path}: pontosan egy követelmény-formának kellene szerepelnie, de ${usedShapes.length} van (${usedShapes.join(', ')})`).toBe(1)
    }
  })

  it('a "perm" mindig nem-üres string, az "anyPerm" mindig nem-üres tömb', () => {
    for (const [path, access] of Object.entries(ROUTE_ACCESS)) {
      if ('perm' in access) {
        expect(typeof access.perm, `${path}: perm nem string`).toBe('string')
        expect(access.perm.length, `${path}: perm üres string`).toBeGreaterThan(0)
      }
      if ('anyPerm' in access) {
        expect(Array.isArray(access.anyPerm), `${path}: anyPerm nem tömb`).toBe(true)
        expect(access.anyPerm.length, `${path}: anyPerm üres tömb`).toBeGreaterThan(0)
      }
    }
  })
})

describe('hasRouteAccess — a döntési logika minden ágra', () => {
  it('public: mindig true, can()/user-t nem is nézi', () => {
    expect(hasRouteAccess({ public: true }, {})).toBe(true)
  })

  it('perm: a can() eredményét adja vissza', () => {
    const ctx = { can: (p) => p === 'x.y' }
    expect(hasRouteAccess({ perm: 'x.y' }, ctx)).toBe(true)
    expect(hasRouteAccess({ perm: 'other' }, ctx)).toBe(false)
  })

  it('anyPerm: ha a felsoroltak közül bármelyikre true, akkor true', () => {
    const ctx = { can: (p) => p === 'b' }
    expect(hasRouteAccess({ anyPerm: ['a', 'b'] }, ctx)).toBe(true)
    expect(hasRouteAccess({ anyPerm: ['a', 'c'] }, ctx)).toBe(false)
  })

  it('superadminOnly: kizárólag user.is_superadmin számít, a can() eredménye irreleváns', () => {
    expect(hasRouteAccess({ superadminOnly: true }, { user: { is_superadmin: true }, can: () => false })).toBe(true)
    expect(hasRouteAccess({ superadminOnly: true }, { user: { is_superadmin: false }, can: () => true })).toBe(false)
  })

  it('check: a megadott függvény dönt, params-t is megkapja', () => {
    const access = { check: ({ params }) => params?.id === '7' }
    expect(hasRouteAccess(access, { params: { id: '7' } })).toBe(true)
    expect(hasRouteAccess(access, { params: { id: '8' } })).toBe(false)
  })

  it('hiányzó/undefined access → true (nincs védett route ilyennel, de fail-open helyett védett route esetén mindig kap access-t)', () => {
    expect(hasRouteAccess(undefined, {})).toBe(true)
  })

  it('üres ctx (betöltetlen állapot) minden nem-public formánál false-t ad — fail-closed', () => {
    const ctx = { user: undefined, can: () => false }
    expect(hasRouteAccess({ perm: 'x' }, ctx)).toBe(false)
    expect(hasRouteAccess({ anyPerm: ['x', 'y'] }, ctx)).toBe(false)
    expect(hasRouteAccess({ superadminOnly: true }, ctx)).toBe(false)
  })
})
