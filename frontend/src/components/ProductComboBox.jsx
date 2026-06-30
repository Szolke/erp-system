import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { products as productsApi } from '../api/products'

/**
 * Select2-jellegű termékkereső.
 * – Trigger-gomb: kattintásra megnyílik a dropdown, azonnal mutatja a terméklistát.
 * – A dropdown tetején keresőmező; gépelésre (200ms debounce) szűr az API-n.
 * – Portal-ba rendereljük, hogy a tábla overflow:hidden ne vágja el.
 * – Kívülre kattintásra és Escape-re bezárul.
 */
export default function ProductComboBox({ selectedProduct, onSelect, onClear }) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [options, setOptions] = useState([])
  const [loading, setLoading] = useState(false)
  const [dropPos, setDropPos] = useState(null)

  const triggerRef = useRef(null)
  const searchRef = useRef(null)
  const debounceRef = useRef(null)

  // Termékek betöltése megnyitáskor / kereséskor
  async function loadProducts(q = '') {
    setLoading(true)
    try {
      const res = await productsApi.list(q ? { search: q } : undefined)
      setOptions(res.data.data ?? [])
    } catch { /* silent */ } finally {
      setLoading(false)
    }
  }

  // Megnyitás: pozíció számítás + termékek betöltése + keresőmező fókuszálása
  function openDropdown() {
    if (triggerRef.current) setDropPos(triggerRef.current.getBoundingClientRect())
    setSearch('')
    setOpen(true)
    loadProducts('')
    setTimeout(() => searchRef.current?.focus(), 0)
  }

  function closeDropdown() {
    setOpen(false)
    setSearch('')
  }

  // Kívülre kattintás → bezárás
  useEffect(() => {
    if (!open) return
    function onOutside(e) {
      if (!triggerRef.current?.contains(e.target)) closeDropdown()
    }
    document.addEventListener('mousedown', onOutside)
    return () => document.removeEventListener('mousedown', onOutside)
  }, [open])

  // Escape → bezárás
  useEffect(() => {
    if (!open) return
    function onKey(e) { if (e.key === 'Escape') closeDropdown() }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open])

  // Görgetés → bezárás (dropdown pozíciója nem követi a scrollt)
  useEffect(() => {
    if (!open) return
    function onScroll() { closeDropdown() }
    window.addEventListener('scroll', onScroll, true)
    return () => window.removeEventListener('scroll', onScroll, true)
  }, [open])

  function handleSearchChange(e) {
    const val = e.target.value
    setSearch(val)
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => loadProducts(val), 200)
  }

  function handleSelect(product) {
    closeDropdown()
    onSelect(product)
  }

  function handleClear(e) {
    e.stopPropagation()
    onClear()
  }

  function handleTriggerClick() {
    if (open) closeDropdown(); else openDropdown()
  }

  // ── Dropdown (Portal) ──────────────────────────────────────────
  const dropdown = open && dropPos ? createPortal(
    <div
      className="sp-dropdown"
      style={{ position: 'fixed', top: dropPos.bottom + 2, left: dropPos.left, width: dropPos.width, zIndex: 9999 }}
    >
      <div className="sp-search-wrap">
        <input
          ref={searchRef}
          className="sp-search-input"
          type="text"
          value={search}
          onChange={handleSearchChange}
          placeholder="Keresés terméknév vagy cikkszám szerint…"
          autoComplete="off"
          onMouseDown={(e) => e.stopPropagation()}
        />
      </div>
      {loading && <div className="sp-status">Betöltés…</div>}
      {!loading && options.length === 0 && <div className="sp-status">Nincs találat</div>}
      {!loading && options.length > 0 && (
        <ul className="sp-list">
          {options.map((p) => (
            <li
              key={p.id}
              className={'sp-option' + (selectedProduct?.id === p.id ? ' is-selected' : '')}
              onMouseDown={(e) => { e.preventDefault(); handleSelect(p) }}
            >
              <span className="sp-opt-sku">{p.sku}</span>
              <span className="sp-opt-name">{p.name}</span>
              <span className="sp-opt-price">
                {Number(p.base_price).toLocaleString('hu')} {p.base_currency}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>,
    document.body,
  ) : null

  // ── Trigger ────────────────────────────────────────────────────
  return (
    <div
      ref={triggerRef}
      className={'sp-trigger' + (open ? ' is-open' : '') + (!selectedProduct ? ' is-empty' : '')}
      onClick={handleTriggerClick}
      role="combobox"
      aria-expanded={open}
    >
      {selectedProduct ? (
        <span className="sp-value">
          <span className="sp-val-sku">{selectedProduct.sku}</span>
          <span className="sp-val-name">{selectedProduct.name}</span>
        </span>
      ) : (
        <span className="sp-placeholder">— Válassz terméket —</span>
      )}

      <span className="sp-icons">
        {selectedProduct && (
          <button type="button" className="sp-clear" onClick={handleClear} title="Törlés">×</button>
        )}
        <span className="sp-arrow">{open ? '▲' : '▼'}</span>
      </span>

      {dropdown}
    </div>
  )
}
