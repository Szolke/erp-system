import { useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { products as productsApi } from '../api/products'

/**
 * Kötelező termékkereső.
 * A dropdown-t React Portal-lal a document.body-ba rendereljük, hogy a
 * tábla overflow:hidden CSS-e ne vágja el.
 */
export default function ProductComboBox({ selectedProduct, onSelect, onClear }) {
  const [query, setQuery] = useState('')
  const [options, setOptions] = useState([])
  const [open, setOpen] = useState(false)
  const [rect, setRect] = useState(null)
  const debounceRef = useRef(null)
  const inputRef = useRef(null)

  function calcRect() {
    if (inputRef.current) setRect(inputRef.current.getBoundingClientRect())
  }

  function handleChange(e) {
    const val = e.target.value
    setQuery(val)
    calcRect()
    clearTimeout(debounceRef.current)

    if (!val.trim()) { setOptions([]); setOpen(false); return }

    debounceRef.current = setTimeout(async () => {
      try {
        const res = await productsApi.list({ search: val })
        const list = res.data.data ?? []
        setOptions(list)
        setOpen(list.length > 0)
      } catch { /* hálózati hiba */ }
    }, 200)
  }

  function handleSelect(product) {
    setOpen(false)
    setQuery('')
    setOptions([])
    onSelect(product)
  }

  function handleClear() {
    setOpen(false)
    setQuery('')
    setOptions([])
    onClear()
    setTimeout(() => inputRef.current?.focus(), 0)
  }

  // Kiválasztott termék — chip megjelenítés
  if (selectedProduct) {
    return (
      <div className="product-selected-chip">
        <span className="chip-sku">{selectedProduct.sku}</span>
        <span className="chip-name">{selectedProduct.name}</span>
        <button type="button" className="chip-clear" onClick={handleClear} title="Törlés">×</button>
      </div>
    )
  }

  // Kereső állapot
  const dropdown = open && options.length > 0 && rect
    ? createPortal(
        <ul
          className="product-dropdown"
          // position:fixed = viewport-relatív, nem érinti semmilyen szülő overflow
          style={{ position: 'fixed', top: rect.bottom + 4, left: rect.left, width: Math.max(rect.width, 320), zIndex: 9999 }}
        >
          {options.map((p) => (
            <li
              key={p.id}
              // onMouseDown + preventDefault: megakadályozza, hogy az input elveszítse a fókuszt a dropdown bezáródása előtt
              onMouseDown={(e) => { e.preventDefault(); handleSelect(p) }}
            >
              <span className="pd-sku">{p.sku}</span>
              <span className="pd-name">{p.name}</span>
              <span className="pd-price">{Number(p.base_price).toLocaleString('hu')} {p.base_currency}</span>
            </li>
          ))}
        </ul>,
        document.body,
      )
    : null

  return (
    <div className="product-combobox">
      <input
        ref={inputRef}
        type="text"
        value={query}
        onChange={handleChange}
        onFocus={() => { calcRect(); if (options.length > 0) setOpen(true) }}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        placeholder="Terméknév vagy cikkszám…"
        autoComplete="off"
        required
      />
      {dropdown}
    </div>
  )
}
