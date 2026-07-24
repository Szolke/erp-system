import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'

describe('vitest setup smoke test', () => {
  it('runs basic assertions', () => {
    expect(1 + 1).toBe(2)
  })

  it('renders a minimal React component', () => {
    render(<div>hello</div>)
    expect(screen.getByText('hello')).toBeInTheDocument()
  })
})
