import { useRef, useState } from 'react'
import { products as productsApi } from '../api/products'

/**
 * Kötelező termékkereső: szabad szöveges bevitel nem megengedett,
 * kizárólag a termék-listából lehet választani.
 *
 * Props:
 *   selectedProduct  – a kiválasztott termék objektum, vagy null
 *   onSelect(p)      – meghívódik termék kiválasztásakor
 *   onClear()        – meghívódik a × gombra
 */
export default function ProductComboBox({ selectedProduct, onSelect, onClear }) {
  const [query, setQuery] = useState('')
  const [options, setOptions] = useState([])
  const [open, setOpen] = useState(false)
  const debounceRef = useRef(null)
  const wrapRef = useRef(null)
  const inputRef = useRef(null)

  // --- keresési logika -------------------------------------------------------

  function handleQueryChange(e) {
    const val = e.target.value
    setQuery(val)
    clearTimeout(debounceRef.current)

    if (val.trim().length === 0) { setOptions([]); setOpen(false); return }

    debounceRef.current = setTimeout(async () => {
      try {
        const res = await productsApi.list({ search: val })
        const list = res.data.data ?? []
        setOptions(list)
        setOpen(list.length > 0)
      } catch { /* hálózati hiba esetén nem szükséges kezelni */ }
    }, 200)
  }

  function handleSelect(product) {
    setQuery('')
    setOptions([])
    setOpen(false)
    onSelect(product)
  }

  function handleClear() {
    setQuery('')
    setOptions([])
    setOpen(false)
    onClear()
    // Megnyitjuk a keresőt azonnal visszaválasztáshoz
    setTimeout(() => inputRef.current?.focus(), 0)
  }

  function handleBlur(e) {
    if (!wrapRef.current?.contains(e.relatedTarget)) {
      setOpen(false)
    }
  }

  // --- renderelés ------------------------------------------------------------

  // Kiválasztott állapot: terméknév chip + × gomb
  if (selectedProduct) {
    return (
      <div className="product-selected-chip">
        <span className="chip-sku">{selectedProduct.sku}</span>
        <span className="chip-name">{selectedProduct.name}</span>
        <button
          type="button"
          className="chip-clear"
          onClick={handleClear}
          title="Törlés, más termék választása"
        >
          ×
        </button>
      </div>
    )
  }

  // Üres állapot: kereső input + legördülő
  return (
    <div ref={wrapRef} className="product-combobox" onBlur={handleBlur}>
      <input
        ref={inputRef}
        type="text"
        value={query}
        onChange={handleQueryChange}
        onFocus={() => { if (options.length > 0) setOpen(true) }}
        placeholder="Terméknév vagy cikkszám…"
        autoComplete="off"
        // required az HTML szinten: select nélkül nem lehet submit
        required
      />
      {open && (
        <ul className="product-dropdown">
          {options.map((p) => (
            <li
              key={p.id}
              // onMouseDown: az input onBlur előtt fut, így a dropdown nem záródik be
              onMouseDown={(e) => { e.preventDefault(); handleSelect(p) }}
            >
              <span className="pd-sku">{p.sku}</span>
              <span className="pd-name">{p.name}</span>
              <span className="pd-price">
                {Number(p.base_price).toLocaleString('hu')} {p.base_currency}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
