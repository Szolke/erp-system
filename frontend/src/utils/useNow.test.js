import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useNow } from './useNow'

// A hook időzítőre épül, ezért végig hamis időzítőkkel dolgozunk — valós
// várakozás nélkül, determinisztikusan.

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date(2026, 6, 30, 12, 0, 0))
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useNow', () => {
  it('induláskor az aktuális időbélyeget adja', () => {
    const { result } = renderHook(() => useNow())

    expect(result.current).toBe(Date.now())
  })

  it('az alapértelmezett 30 másodperc letelte után frissül', () => {
    const { result } = renderHook(() => useNow())
    const start = result.current

    act(() => { vi.advanceTimersByTime(30000) })

    expect(result.current).toBe(start + 30000)
  })

  it('a 30 másodperc LETELTE ELŐTT még nem frissül', () => {
    const { result } = renderHook(() => useNow())
    const start = result.current

    act(() => { vi.advanceTimersByTime(29999) })

    expect(result.current).toBe(start)
  })

  it('több periódus alatt ismételten frissül', () => {
    const { result } = renderHook(() => useNow())
    const start = result.current

    act(() => { vi.advanceTimersByTime(90000) })

    expect(result.current).toBe(start + 90000)
  })

  it('egyedi intervallumot is elfogad', () => {
    const { result } = renderHook(() => useNow(1000))
    const start = result.current

    act(() => { vi.advanceTimersByTime(1000) })

    expect(result.current).toBe(start + 1000)
  })

  it('unmountkor leállítja az időzítőt', () => {
    // Enélkül a "frissítve X perce" feliratok időzítői felhalmozódnának minden
    // oldalváltásnál, és elhagyott komponensekre próbálnának állapotot írni.
    const clearSpy = vi.spyOn(globalThis, 'clearInterval')
    const { unmount } = renderHook(() => useNow())

    unmount()

    expect(clearSpy).toHaveBeenCalled()
    clearSpy.mockRestore()
  })
})
