import { useEffect, useMemo, useRef, useState } from 'react'

function normalize(str) {
  return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

/**
 * Generic searchable single-select combobox (WAI-ARIA "combobox with listbox
 * popup", editable, list autocomplete). Not coupled to any specific data —
 * `options` is a plain {value, label}[] array.
 *
 * The input always displays the currently selected option's label when not
 * actively being typed into; free text is never committed as a value —
 * blurring/closing without an explicit selection reverts the display text.
 */
export default function SearchableSelect({
  id,
  value,
  onChange,
  options,
  placeholder = '',
  searchPlaceholder,
  noResultsLabel = 'No results',
  disabled = false,
  loading = false,
}) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [highlightedIndex, setHighlightedIndex] = useState(-1)
  const rootRef = useRef(null)

  const selected = useMemo(() => options.find((o) => o.value === value) ?? null, [options, value])

  // Display text: the selected option's label while closed, the typed query while open.
  const displayValue = open ? query : (selected?.label ?? '')

  const filtered = useMemo(() => {
    if (!open || !query.trim()) return options
    const needle = normalize(query.trim())
    return options.filter((o) => normalize(o.label).includes(needle) || normalize(o.value).includes(needle))
  }, [options, query, open])

  useEffect(() => {
    function handleClickOutside(e) {
      if (rootRef.current && !rootRef.current.contains(e.target)) {
        setOpen(false)
        setQuery('')
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  function openList() {
    if (disabled) return
    setOpen(true)
    setQuery('')
    const idx = options.findIndex((o) => o.value === value)
    setHighlightedIndex(idx >= 0 ? idx : 0)
  }

  function closeList() {
    setOpen(false)
    setQuery('')
    setHighlightedIndex(-1)
  }

  function selectOption(opt) {
    onChange(opt.value)
    closeList()
  }

  function handleKeyDown(e) {
    if (disabled) return

    if (!open) {
      if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        openList()
      }
      return
    }

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault()
        setHighlightedIndex((i) => Math.min(i + 1, filtered.length - 1))
        break
      case 'ArrowUp':
        e.preventDefault()
        setHighlightedIndex((i) => Math.max(i - 1, 0))
        break
      case 'Enter':
        e.preventDefault()
        if (highlightedIndex >= 0 && filtered[highlightedIndex]) {
          selectOption(filtered[highlightedIndex])
        }
        break
      case 'Escape':
        e.preventDefault()
        closeList()
        break
      default:
        break
    }
  }

  const activeOptionId = open && highlightedIndex >= 0 && filtered[highlightedIndex]
    ? `${id}-option-${highlightedIndex}`
    : undefined

  return (
    <div ref={rootRef} style={{ position: 'relative' }}>
      <input
        id={id}
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={`${id}-listbox`}
        aria-autocomplete="list"
        aria-activedescendant={activeOptionId}
        autoComplete="off"
        disabled={disabled || loading}
        placeholder={open ? (searchPlaceholder ?? placeholder) : placeholder}
        value={displayValue}
        onFocus={openList}
        onClick={() => { if (!open) openList() }}
        onChange={(e) => {
          setQuery(e.target.value)
          if (!open) setOpen(true)
          setHighlightedIndex(0)
        }}
        onKeyDown={handleKeyDown}
      />

      {open && (
        <ul
          id={`${id}-listbox`}
          role="listbox"
          style={{
            position: 'absolute',
            zIndex: 20,
            top: 'calc(100% + 4px)',
            left: 0,
            right: 0,
            maxHeight: 260,
            overflowY: 'auto',
            margin: 0,
            padding: 4,
            listStyle: 'none',
            background: 'var(--color-surface)',
            border: '1px solid var(--color-border)',
            borderRadius: 6,
            boxShadow: '0 4px 16px rgba(0,0,0,0.15)',
          }}
        >
          {filtered.length === 0 && (
            <li className="text-muted" style={{ padding: '8px 10px', fontSize: 13 }}>
              {noResultsLabel}
            </li>
          )}
          {filtered.map((opt, i) => (
            <li
              key={opt.value}
              id={`${id}-option-${i}`}
              role="option"
              aria-selected={opt.value === value}
              onMouseDown={(e) => { e.preventDefault(); selectOption(opt) }}
              onMouseEnter={() => setHighlightedIndex(i)}
              style={{
                padding: '6px 10px',
                borderRadius: 4,
                fontSize: 13,
                cursor: 'pointer',
                background: i === highlightedIndex ? 'var(--color-hover)' : 'transparent',
                fontWeight: opt.value === value ? 600 : 400,
                color: 'var(--color-text)',
              }}
            >
              {opt.label}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
