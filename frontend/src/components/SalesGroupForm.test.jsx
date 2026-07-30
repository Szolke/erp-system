import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import SalesGroupForm from './SalesGroupForm'
import { fieldError, salesGroupDisplayName } from '../utils/salesGroups'

/** Axios-alakú elutasítás: { response: { status, data: { message, errors } } } */
function apiError(status, data) {
  return { response: { status, data } }
}

describe('SalesGroupForm — szerver-hibák megjelenítése', () => {
  it('a mezőhibát (422 errors.name) a név-mező alatt mutatja, a `message`-t nem duplázza', async () => {
    const user   = userEvent.setup()
    const onSave = vi.fn().mockRejectedValue(apiError(422, {
      message: 'The given data was invalid.',
      errors:  { name: ['Ilyen nevű csoport már létezik (kis- és nagybetű-független egyediség).'] },
    }))

    render(<SalesGroupForm onSave={onSave} onCancel={() => {}} />)

    await user.type(screen.getByPlaceholderText('pl. Észak'), 'Észak')
    await user.click(screen.getByRole('button', { name: 'Létrehozás' }))

    expect(await screen.findByText(/Ilyen nevű csoport már létezik/)).toBeInTheDocument()
    // A generikus `message` mezőhibák mellett szándékosan nem jelenik meg.
    expect(screen.queryByText('The given data was invalid.')).not.toBeInTheDocument()
  })

  it('a cégválasztó hibáját (errors.company_id) a renderBeforeFields slotnak adja át', async () => {
    const user   = userEvent.setup()
    const onSave = vi.fn().mockRejectedValue(apiError(422, {
      errors: { company_id: ['Érvényes cél céget kell megadni.'] },
    }))

    render(
      <SalesGroupForm
        onSave={onSave}
        onCancel={() => {}}
        renderBeforeFields={(fieldErrors) => (
          <div>
            <label htmlFor="target">Cél cég</label>
            <select id="target" defaultValue=""><option value="">—</option></select>
            <div>{fieldError(fieldErrors, 'company_id')}</div>
          </div>
        )}
      />
    )

    await user.type(screen.getByPlaceholderText('pl. Észak'), 'Észak')
    await user.click(screen.getByRole('button', { name: 'Létrehozás' }))

    expect(await screen.findByText('Érvényes cél céget kell megadni.')).toBeInTheDocument()
  })

  it('mezőhiba nélküli hibát (403) a describeError szövegével, a form fejében mutat', async () => {
    const user   = userEvent.setup()
    const onSave = vi.fn().mockRejectedValue(apiError(403, { message: 'This action is unauthorized.' }))

    render(
      <SalesGroupForm
        onSave={onSave}
        onCancel={() => {}}
        describeError={() => 'A cél cégben ki van kapcsolva a modul.'}
      />
    )

    await user.type(screen.getByPlaceholderText('pl. Észak'), 'Észak')
    await user.click(screen.getByRole('button', { name: 'Létrehozás' }))

    expect(await screen.findByText('A cél cégben ki van kapcsolva a modul.')).toBeInTheDocument()
    // A Laravel angol default üzenete nem szivárog ki a felületre.
    expect(screen.queryByText('This action is unauthorized.')).not.toBeInTheDocument()
  })
})

describe('SalesGroupForm — submit-kapu és előnézet', () => {
  it('disableSubmit mellett a mentés tiltott, akkor is, ha már van név', async () => {
    const user = userEvent.setup()

    render(<SalesGroupForm onSave={vi.fn()} onCancel={() => {}} disableSubmit />)

    await user.type(screen.getByPlaceholderText('pl. Észak'), 'Észak')
    expect(screen.getByRole('button', { name: 'Létrehozás' })).toBeDisabled()
  })

  it('a megjelenítőnév-előnézet a prefixet a backend képletével fűzi össze', async () => {
    const user = userEvent.setup()

    render(<SalesGroupForm prefix="ACME" onSave={vi.fn()} onCancel={() => {}} />)

    await user.type(screen.getByPlaceholderText('pl. Észak'), 'Észak')
    expect(screen.getByText('ACME_Észak')).toBeInTheDocument()
    expect(salesGroupDisplayName(null, 'Észak')).toBe('Észak')
  })
})
