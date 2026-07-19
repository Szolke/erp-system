import { useEffect } from 'react'

/**
 * Közös popover-viselkedés a Kimutatások szűrősáv három popoverjéhez
 * (dátum-tartomány, dátum-alapja, szűrők): kívülre kattintás és Escape zár,
 * záráskor a fókusz visszakerül a nyitó gombra. `containerRef` a trigger +
 * panel közös szülőjére mutat (a kattintás-figyelés ezen belülre nem zár).
 */
export function usePopoverDismiss(open, onClose, containerRef, triggerRef) {
  useEffect(() => {
    if (!open) return

    function handlePointerDown(e) {
      if (!containerRef.current?.contains(e.target)) {
        onClose()
      }
    }
    function handleKeyDown(e) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose()
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])
}
