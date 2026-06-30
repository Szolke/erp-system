import { useEffect, useRef, useState } from 'react'
import { products as productsApi } from '../api/products'

/**
 * Kereshető termékkereső.
 * - Gépelésre (200ms debounce) lekérdezi az API-t.
 * - Termék kiválasztásakor onSelect(product) hívódik.
 * - Szabad szöveges bevitel is marad: onDescriptionChange(str) minden módosításnál.
 */
export default function ProductComboBox({ description, onDescriptionChange, onSelect }) {
  const [query, setQuery] = useState(description)
  const [options, setOptions] = useState([])
  const [open, setOpen] = useState(false)
  const debounceRef = useRef(null)
  const wrapRef = useRef(null)

  // Sync ha a szülő kívülről visszaállítja (pl. sor törlés/reset)
  useEffect(() => { setQuery(description) }, [description])

  function handleChange(e) {
    const val = e.target.value
    setQuery(val)
    onDescriptionChange(val)

    clearTimeout(debounceRef.current)
    if (val.trim().length === 0) { setOptions([]); setOpen(false); return }

    debounceRef.current = setTimeout(async () => {
      try {
        const res = await productsApi.list({ search: val })
        setOptions(res.data.data ?? [])
        setOpen(true)
      } catch { /* hálózati hiba esetén csendben elnyelünk */ }
    }, 200)
  }

  function handleSelect(product) {
    setQuery(product.name)
    setOpen(false)
    setOptions([])
    onSelect(product)
  }

  function handleBlur(e) {
    // Csak akkor zárjuk be, ha a fókusz valóban kilép a komponensből
    if (!wrapRef.current?.contains(e.relatedTarget)) {
      setOpen(false)
    }
  }

  return (
    <div ref={wrapRef} className="product-combobox" onBlur={handleBlur}>
      <input
        type="text"
        value={query}
        onChange={handleChange}
        onFocus={() => { if (options.length > 0) setOpen(true) }}
        placeholder="Terméknév vagy cikkszám…"
        autoComplete="off"
      />
      {open && options.length > 0 && (
        <ul className="product-dropdown">
          {options.map((p) => (
            <li
              key={p.id}
              // onMouseDown: az input onBlur előtt fut, így a dropdown nem záródik be idő előtt
              onMouseDown={(e) => { e.preventDefault(); handleSelect(p) }}
            >
              <span className="pd-sku">{p.sku}</span>
              <span className="pd-name">{p.name}</span>
              <span className="pd-price">{Number(p.base_price).toLocaleString('hu')} {p.base_currency}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
