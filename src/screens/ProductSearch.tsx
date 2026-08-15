import { useEffect, useMemo, useRef, useState } from 'react'
import type { Product } from '../db'
import { formatMoney } from '../sales'

/**
 * The only way a product enters the sale.
 *
 * Matches are a dropdown over the search box, not a permanent grid. A grid of
 * the whole catalogue is the wrong thing for a till: the shop this was built
 * against has 87 products and others have hundreds, so the useful list is never
 * "everything" — it is the two or three rows that match what was just typed or
 * scanned. The space below belongs to what has actually been rung up.
 *
 * A scanner behaves like a keyboard that types fast and presses Enter. An exact
 * barcode or code hit is added immediately and the box clears, so the cashier
 * can scan the next item without touching anything.
 */
export function ProductSearch({
  products,
  onPick,
  onMiss,
}: {
  products: Product[]
  onPick: (product: Product) => void
  onMiss: (code: string) => void
}) {
  const [query, setQuery] = useState('')
  const [highlight, setHighlight] = useState(0)
  const [open, setOpen] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)
  const boxRef = useRef<HTMLDivElement>(null)

  const needle = query.trim().toLowerCase()

  const matches = useMemo(() => {
    if (needle === '') return []

    const scored = products
      .filter((p) => !p.deleted)
      .map((p) => {
        const name = (p.name ?? '').toLowerCase()
        const code = (p.code ?? '').toLowerCase()
        const barcode = (p.barcode ?? '').toLowerCase()

        // Exact identifier beats a name that merely contains the text, so a
        // scanned code never loses to a product whose name happens to include
        // those digits.
        if (barcode === needle || code === needle) return { p, rank: 0 }
        if (name === needle) return { p, rank: 1 }
        if (name.startsWith(needle)) return { p, rank: 2 }
        if (name.includes(needle)) return { p, rank: 3 }
        if (code.includes(needle) || barcode.includes(needle)) return { p, rank: 4 }
        return null
      })
      .filter((x): x is { p: Product; rank: number } => x !== null)

    scored.sort((a, b) => a.rank - b.rank)
    return scored.slice(0, 8).map((x) => x.p)
  }, [products, needle])

  useEffect(() => setHighlight(0), [needle])

  useEffect(() => {
    function onClickAway(event: MouseEvent) {
      if (!boxRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickAway)
    return () => document.removeEventListener('mousedown', onClickAway)
  }, [])

  function take(product: Product) {
    onPick(product)
    setQuery('')
    setOpen(false)
    inputRef.current?.focus()
  }

  function onKeyDown(event: React.KeyboardEvent) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setHighlight((h) => Math.min(h + 1, matches.length - 1))
      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setHighlight((h) => Math.max(h - 1, 0))
      return
    }
    if (event.key === 'Escape') {
      setQuery('')
      setOpen(false)
      return
    }
    if (event.key !== 'Enter') return

    event.preventDefault()
    if (needle === '') return

    // A scanner's Enter must land on the scanned item, never on whatever
    // happened to be highlighted from the previous keystroke.
    const exact = matches.find(
      (p) => p.barcode?.toLowerCase() === needle || p.code?.toLowerCase() === needle,
    )
    if (exact) return take(exact)

    if (matches[highlight]) return take(matches[highlight])

    onMiss(query.trim())
    setQuery('')
  }

  return (
    <div className="search-box" ref={boxRef}>
      <span className="search-icon" aria-hidden>
        ⌕
      </span>
      <input
        ref={inputRef}
        autoFocus
        value={query}
        onChange={(e) => {
          setQuery(e.target.value)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        placeholder="Mahsulot kodi yoki nomi… (barcode skanerlang)"
      />

      {open && needle !== '' && (
        <div className="search-results">
          {matches.length === 0 && <div className="search-empty">Mahsulot topilmadi</div>}

          {matches.map((product, index) => {
            const qty = product.quantity
            return (
              <button
                type="button"
                key={product.id}
                className={`search-hit ${index === highlight ? 'on' : ''}`}
                onMouseEnter={() => setHighlight(index)}
                onClick={() => take(product)}
              >
                <span className="grow">
                  <span className="nm">{product.name}</span>
                  <span className="sub">
                    {product.barcode ?? product.code ?? '—'}
                    {' · qoldiq '}
                    {/* null means never inventoried, which is not zero. */}
                    {qty === null ? '—' : formatMoney(qty)}
                  </span>
                </span>
                <span className="pr">{formatMoney(product.price ?? 0)}</span>
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
