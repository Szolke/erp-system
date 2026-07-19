import { useEffect, useState } from 'react'

/** Az aktuális idő, 30 másodpercenként frissítve — a "frissítve X perce" feliratokhoz. */
export function useNow(intervalMs = 30000) {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), intervalMs)
    return () => clearInterval(id)
  }, [intervalMs])

  return now
}
