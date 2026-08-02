import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import SearchableSelect from './SearchableSelect'

// A SearchableSelect szándékosan függőségmentes (nem használ fordítást, API-t
// vagy routert) — az `options` egy sima {value, label}[] tömb. Ezért itt semmit
// nem kell mockolni: a VALÓDI komponenst rendereljük, és a tényleges
// DOM-viselkedést teszteljük.
//
// Az ékezetes címkék nem díszítés: a `normalize()` NFD-bontással szedi le az
// ékezeteket, és pontosan ezt az ágat csak ékezetes adaton lehet megfogni.
const OPTIONS = [
  { value: 'HU', label: 'Magyarország' },
  { value: 'DE', label: 'Németország' },
  { value: 'AT', label: 'Ausztria' },
  { value: 'US', label: 'Amerikai Egyesült Államok' },
]

function setup(props = {}) {
  const onChange = props.onChange ?? vi.fn()
  const utils = render(
    <SearchableSelect
      id="country"
      value={null}
      onChange={onChange}
      options={OPTIONS}
      placeholder="Válassz országot"
      {...props}
    />,
  )
  return { ...utils, onChange, input: screen.getByRole('combobox') }
}

function optionLabels() {
  return screen.getAllByRole('option').map((o) => o.textContent)
}

describe('SearchableSelect — zárt állapot', () => {
  it('a kiválasztott opció CÍMKÉJÉT mutatja, nem a value-ját', () => {
    setup({ value: 'DE' })

    expect(screen.getByRole('combobox')).toHaveValue('Németország')
  })

  it('üresen marad, ha a value egyik opcióra sem illeszkedik', () => {
    // Pl. a törzsadat egy azóta törölt országkódot tartalmaz: ilyenkor
    // szándékosan üres a mező, nem a nyers kód villan be.
    setup({ value: 'XX' })

    expect(screen.getByRole('combobox')).toHaveValue('')
  })

  it('nem rendereli a listát, amíg nincs megnyitva', () => {
    setup()

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('a zárt állapot ARIA-jelzése helyes', () => {
    const { input } = setup()

    expect(input).toHaveAttribute('aria-expanded', 'false')
    expect(input).toHaveAttribute('aria-controls', 'country-listbox')
    expect(input).toHaveAttribute('aria-autocomplete', 'list')
    expect(input).not.toHaveAttribute('aria-activedescendant')
  })
})

describe('SearchableSelect — megnyitás', () => {
  it('fókuszra megnyílik és kilistázza az összes opciót', async () => {
    const { input } = setup()

    await userEvent.click(input)

    expect(input).toHaveAttribute('aria-expanded', 'true')
    expect(optionLabels()).toEqual([
      'Magyarország', 'Németország', 'Ausztria', 'Amerikai Egyesült Államok',
    ])
  })

  it('megnyitáskor KIÜRÍTI a mezőt, hogy tiszta lappal lehessen keresni', async () => {
    // Ez szándékos: a `displayValue` nyitott állapotban a keresőkifejezés, amit
    // az `openList()` üresre állít. A kiválasztott címke nem marad benn, hogy ne
    // kelljen kitörölni gépelés előtt — bezáráskor viszont visszatér.
    const { input } = setup({ value: 'DE' })

    await userEvent.click(input)

    expect(input).toHaveValue('')
  })

  it('nyitott állapotban a kereső-placeholderre vált', async () => {
    const { input } = setup({ searchPlaceholder: 'Keresés…' })

    expect(input).toHaveAttribute('placeholder', 'Válassz országot')
    await userEvent.click(input)

    expect(input).toHaveAttribute('placeholder', 'Keresés…')
  })

  it('kereső-placeholder hiányában a sima placeholdert tartja meg nyitva is', async () => {
    const { input } = setup()

    await userEvent.click(input)

    expect(input).toHaveAttribute('placeholder', 'Válassz országot')
  })

  it('megnyitáskor a KIVÁLASZTOTT opcióra áll a kiemelés', async () => {
    const { input } = setup({ value: 'AT' })

    await userEvent.click(input)

    expect(input).toHaveAttribute('aria-activedescendant', 'country-option-2')
  })

  it('kiválasztás nélkül az első opcióra áll a kiemelés', async () => {
    const { input } = setup()

    await userEvent.click(input)

    expect(input).toHaveAttribute('aria-activedescendant', 'country-option-0')
  })

  it('a kiválasztott opciót aria-selected jelöli, a többit nem', async () => {
    const { input } = setup({ value: 'AT' })

    await userEvent.click(input)

    const selected = screen.getAllByRole('option').filter(
      (o) => o.getAttribute('aria-selected') === 'true',
    )
    expect(selected).toHaveLength(1)
    expect(selected[0]).toHaveTextContent('Ausztria')
  })
})

describe('SearchableSelect — szűrés', () => {
  it('ÉKEZET nélkül beírt keresés is megtalálja az ékezetes címkét', async () => {
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.type(input, 'nemet')

    expect(optionLabels()).toEqual(['Németország'])
  })

  it('a keresés kis- és nagybetűre nem érzékeny', async () => {
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.type(input, 'MAGYAR')

    expect(optionLabels()).toEqual(['Magyarország'])
  })

  it('a value-ra (országkódra) is illeszt, nem csak a címkére', async () => {
    // A kód szerinti keresés a gyorsan gépelő felhasználót szolgálja ki:
    // "de" → Németország, holott a címkében nincs benne ez a betűpár.
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.type(input, 'de')

    expect(optionLabels()).toEqual(['Németország'])
  })

  it('a csak whitespace-ből álló keresést üresnek tekinti', async () => {
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.type(input, '   ')

    expect(optionLabels()).toHaveLength(4)
  })

  it('találat nélkül a "nincs találat" feliratot mutatja, opció nélkül', async () => {
    const { input } = setup({ noResultsLabel: 'Nincs találat' })

    await userEvent.click(input)
    await userEvent.type(input, 'zzz')

    expect(screen.queryAllByRole('option')).toHaveLength(0)
    expect(screen.getByText('Nincs találat')).toBeInTheDocument()
  })

  it('gépelésre a kiemelés az első TALÁLATRA ugrik', async () => {
    // Enélkül a szűrés utáni Enter egy már nem látható sort választana ki.
    const { input } = setup({ value: 'US' })

    await userEvent.click(input)
    await userEvent.type(input, 'ausz')

    expect(input).toHaveAttribute('aria-activedescendant', 'country-option-0')
    expect(optionLabels()).toEqual(['Ausztria'])
  })
})

describe('SearchableSelect — kiválasztás', () => {
  it('opcióra kattintva a VALUE-t adja tovább és bezárja a listát', async () => {
    const { input, onChange } = setup()

    await userEvent.click(input)
    await userEvent.click(screen.getByText('Ausztria'))

    expect(onChange).toHaveBeenCalledWith('AT')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('a szűrt listából is a helyes opciót választja ki', async () => {
    const { input, onChange } = setup()

    await userEvent.click(input)
    await userEvent.type(input, 'nemet')
    await userEvent.click(screen.getByText('Németország'))

    expect(onChange).toHaveBeenCalledWith('DE')
  })

  it('egérrel a kiemelés a hover alatti sorra vált', async () => {
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.hover(screen.getByText('Amerikai Egyesült Államok'))

    expect(input).toHaveAttribute('aria-activedescendant', 'country-option-3')
  })
})

describe('SearchableSelect — billentyűzet', () => {
  it('zárt állapotban ArrowDown, Enter és szóköz is megnyitja a listát', async () => {
    for (const key of ['{ArrowDown}', '{Enter}', ' ']) {
      const { input, unmount } = setup()
      input.focus()
      // A fókusz maga is nyit, ezért a billentyű-ág méréséhez előbb Escape-pel
      // zárunk — így a következő leütés valóban a zárt ágon fut végig.
      await userEvent.keyboard('{Escape}')
      expect(input).toHaveAttribute('aria-expanded', 'false')

      await userEvent.keyboard(key)

      expect(input).toHaveAttribute('aria-expanded', 'true')
      unmount()
    }
  })

  it('ArrowDown lefelé lépteti a kiemelést', async () => {
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.keyboard('{ArrowDown}{ArrowDown}')

    expect(input).toHaveAttribute('aria-activedescendant', 'country-option-2')
  })

  it('a kiemelés nem lép túl az utolsó soron', async () => {
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.keyboard('{ArrowDown}'.repeat(10))

    expect(input).toHaveAttribute('aria-activedescendant', 'country-option-3')
  })

  it('a kiemelés nem megy az első sor fölé', async () => {
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.keyboard('{ArrowUp}{ArrowUp}{ArrowUp}')

    expect(input).toHaveAttribute('aria-activedescendant', 'country-option-0')
  })

  it('a léptetés a SZŰRT listához igazodik, nem a teljeshez', async () => {
    // A négyelemű listát az "orszag" kettőre szűkíti; a felső határ ezután 1,
    // hiába volna a teljes listában még két további sor.
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.type(input, 'orszag')
    expect(optionLabels()).toEqual(['Magyarország', 'Németország'])

    await userEvent.keyboard('{ArrowDown}'.repeat(5))

    expect(input).toHaveAttribute('aria-activedescendant', 'country-option-1')
  })

  it('Enter a kiemelt opciót választja ki', async () => {
    const { input, onChange } = setup()

    await userEvent.click(input)
    await userEvent.keyboard('{ArrowDown}{Enter}')

    expect(onChange).toHaveBeenCalledWith('DE')
  })

  it('Escape bezár, de NEM választ ki semmit', async () => {
    const { input, onChange } = setup()

    await userEvent.click(input)
    await userEvent.keyboard('{ArrowDown}{Escape}')

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('SearchableSelect — a szabad szöveg soha nem lesz érték', () => {
  it('Escape után a mező visszaáll a kiválasztott címkére', async () => {
    // Ez a komponens központi szerződése: begépelt, de nem kiválasztott szöveg
    // nem "ragad be" a mezőbe, és nem is kerül fel a hívóhoz.
    const { input, onChange } = setup({ value: 'DE' })

    await userEvent.click(input)
    await userEvent.type(input, 'Kitalált ország')
    await userEvent.keyboard('{Escape}')

    expect(input).toHaveValue('Németország')
    expect(onChange).not.toHaveBeenCalled()
  })

  it('kívülre kattintás után a mező visszaáll a kiválasztott címkére', async () => {
    const { input, onChange } = setup({ value: 'DE' })

    await userEvent.click(input)
    await userEvent.type(input, 'Kitalált ország')
    await userEvent.click(document.body)

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    expect(input).toHaveValue('Németország')
    expect(onChange).not.toHaveBeenCalled()
  })

  it('kiválasztás nélkül üres marad, ha korábban sem volt érték', async () => {
    const { input } = setup()

    await userEvent.click(input)
    await userEvent.type(input, 'zzz')
    await userEvent.keyboard('{Escape}')

    expect(input).toHaveValue('')
  })
})

describe('SearchableSelect — letiltott és betöltő állapot', () => {
  it('letiltva a mező nem szerkeszthető és a lista nem nyílik meg', async () => {
    const { input } = setup({ disabled: true })

    expect(input).toBeDisabled()
    await userEvent.click(input)

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('betöltés közben is letiltott a mező', () => {
    // A `loading` külön prop, de a beviteli mezőre nézve ugyanaz a hatása:
    // a még be nem töltött opciólista fölött ne lehessen keresni.
    const { input } = setup({ loading: true })

    expect(input).toBeDisabled()
  })

  it('letiltva a billentyűzet sem nyitja meg a listát', async () => {
    const { input } = setup({ disabled: true })

    input.focus()
    await userEvent.keyboard('{ArrowDown}')

    expect(input).toHaveAttribute('aria-expanded', 'false')
  })
})
