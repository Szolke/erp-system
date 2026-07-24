import { afterEach } from 'vitest'
import { cleanup } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'

// `globals: false` mellett a Testing Library React-integrációjának beépített
// auto-cleanupja NEM regisztrálódik (az a globális `afterEach` meglétét
// detektálja) — explicit hívás nélkül a render()-ek tesztek között
// felhalmozódnának a DOM-ban, és a screen.getByRole stb. lekérdezések
// "multiple elements found" hibával elszállnának.
afterEach(() => {
  cleanup()
})
