import { describe, expect, it } from 'vitest'
import { describeCrossCompanyError, fieldError } from './salesGroups'

/** Axios-alakú elutasítás: { response: { status, data: { message, errors } } } */
function apiError(status, data = {}) {
  return { response: { status, data } }
}

describe('describeCrossCompanyError', () => {
  it('403-ra a modul-kaput magyarázza, nem a Laravel angol default üzenetét', () => {
    const message = describeCrossCompanyError(apiError(403, { message: 'This action is unauthorized.' }))

    expect(message).toContain('Értékesítő csoportok modul')
    expect(message).not.toContain('unauthorized')
  })

  it('404-re időközbeni törlést jelez', () => {
    expect(describeCrossCompanyError(apiError(404, { message: 'Not found' })))
      .toContain('már nem létezik')
  })

  it('422-nél a szerver üzenetét adja tovább (prefix-hiány: nincs `errors`, csak `message`)', () => {
    expect(describeCrossCompanyError(apiError(422, { message: 'Előbb állíts be prefixet a cégbeállításoknál.' })))
      .toBe('Előbb állíts be prefixet a cégbeállításoknál.')
  })

  it('5xx-re és válasz nélküli hálózati hibára generikus szöveget ad, nyers választ nem', () => {
    expect(describeCrossCompanyError(apiError(500, { message: 'Server Error' })))
      .toBe('A művelet nem sikerült, próbáld újra.')
    expect(describeCrossCompanyError(new Error('Network Error')))
      .toBe('A művelet nem sikerült, próbáld újra.')
  })
})

describe('fieldError', () => {
  it('a Laravel mezőnkénti hiba-tömbjét egy szöveggé fűzi, hiány esetén üres string', () => {
    expect(fieldError({ name: ['Első.', 'Második.'] }, 'name')).toBe('Első. Második.')
    expect(fieldError({ name: ['Első.'] }, 'company_id')).toBe('')
    expect(fieldError(undefined, 'name')).toBe('')
  })
})
