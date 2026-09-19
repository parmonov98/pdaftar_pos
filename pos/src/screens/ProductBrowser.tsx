import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react'
import { formatMoney } from '../sales'
import type { Product } from '../db'

/**
 * The half of the sale screen you pick from.
 *
 * A scanner is faster than any list, so this is not the primary way to add a
 * line — it is the way to add the things that have no barcode, which in a
 * real shop is bread, eggs, and whatever is sold loose. Those are the items a
 * cashier reaches for most often and has to find fastest.
 *
 * Every action here has a key. A till often has no mouse at all, and even
 * where one exists a cashier with a queue does not use it: the hand is on the
 * keyboard or the scanner, and moving to a mouse and back costs more than the
 * keystroke saves.
 */
/**
 * What the sale screen can drive from outside.
 *
 * The arrow keys are handled once, at the window, rather than on this input:
 * a cashier who clicked anywhere — a tab, a total, nothing at all — still
 * expects the arrows to move the list. Keeping the state here and the
 * keystrokes there is what lets both be true.
 */
export type BrowserHandle = {
  move: (delta: number) => void
  pickCurrent: () => void
  hasRows: () => boolean
  /**
   * Put the keyboard in the search box.
   *
   * Needed as a command, not as a consequence of `focused` changing: after
   * the cashier clicks a button the pane is still nominally focused, so the
   * prop does not change, the effect does not re-run, and F3 — the key whose
   * whole job is "give me the search box" — did nothing at all.
   */
  focus: () => void
}

export const ProductBrowser = forwardRef<BrowserHandle, {
  products: Product[]
  /** Whether this pane currently owns the keyboard. */
  focused: boolean
  onPick: (product: Product) => void
  /** The cashier pressed Tab to hand the keyboard to the cart. */
  onLeave: () => void
}>(function ProductBrowser({ products, focused, onPick, onLeave }, ref) {
  const [query, setQuery] = useState('')
  const [cursor, setCursor] = useState(0)
  const listRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const rows = useMemo(() => {
    const needle = query.trim().toLowerCase()

    return products
      .filter((p) => !p.deleted)
      .filter(
        (p) =>
          needle === '' ||
          (p.name ?? '').toLowerCase().includes(needle) ||
          (p.code ?? '').toLowerCase().includes(needle) ||
          (p.barcode ?? '').toLowerCase().includes(needle),
      )
      .sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''))
      .slice(0, 200)
  }, [products, query])

  // A filter that leaves the cursor past the end would make Enter add
  // whatever happened to be last, which is the wrong product at speed.
  useEffect(() => {
    setCursor((c) => Math.min(c, Math.max(0, rows.length - 1)))
  }, [rows.length])

  useEffect(() => {
    if (focused) inputRef.current?.focus()
  }, [focused])

  // Keep the highlighted row on screen while arrowing through a long list.
  useEffect(() => {
    listRef.current?.querySelector('[data-on="1"]')?.scrollIntoView({ block: 'nearest' })
  }, [cursor])

  useImperativeHandle(ref, () => ({
    move: (delta: number) =>
      setCursor((c) => Math.max(0, Math.min(c + delta, rows.length - 1))),
    pickCurrent: () => {
      const product = rows[cursor]
      if (product) onPick(product)
    },
    hasRows: () => rows.length > 0,
    focus: () => inputRef.current?.focus(),
  }), [rows, cursor, onPick])

  function onKeyDown(event: React.KeyboardEvent) {
    // Handled here AND at the window. Stopping propagation is what keeps the
    // cursor from moving twice for one press.
    const mine = ['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Enter', 'Escape']
    if (mine.includes(event.key)) event.stopPropagation()

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setCursor((c) => Math.min(c + 1, rows.length - 1))
      return
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setCursor((c) => Math.max(c - 1, 0))
      return
    }

    // A page at a time, for a catalogue too long to arrow through.
    if (event.key === 'PageDown') {
      event.preventDefault()
      setCursor((c) => Math.min(c + 10, rows.length - 1))
      return
    }

    if (event.key === 'PageUp') {
      event.preventDefault()
      setCursor((c) => Math.max(c - 10, 0))
      return
    }

    if (event.key === 'Enter') {
      event.preventDefault()
      const product = rows[cursor]
      if (product) onPick(product)
      return
    }

    if (event.key === 'Escape') {
      event.preventDefault()
      // First Escape clears the filter, a second hands the keyboard on. A
      // cashier who mistyped wants the list back, not a different pane.
      if (query !== '') setQuery('')
      else onLeave()
      return
    }

    if (event.key === 'Tab' && !event.shiftKey) {
      event.preventDefault()
      onLeave()
    }
  }

  return (
    <div className={`browser ${focused ? 'focused' : ''}`}>
      <div className="browser-head">
        <input
          ref={inputRef}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={onKeyDown}
          placeholder="Mahsulot qidirish…  (F3)"
          aria-label="Mahsulot qidirish"
        />
        <span className="browser-count">{rows.length}</span>
      </div>

      <div className="browser-list" ref={listRef}>
        {rows.length === 0 && (
          <div className="cart-empty">
            {products.length === 0 ? 'Katalog bo‘sh' : 'Topilmadi'}
          </div>
        )}

        {rows.map((product, index) => {
          const qty = product.quantity
          const out = qty !== null && qty <= 0

          return (
            <button
              key={product.id}
              type="button"
              className={`browser-row ${index === cursor ? 'on' : ''}`}
              data-on={index === cursor ? '1' : '0'}
              // Pointer users get the same thing without having to aim at a
              // list that moves under the keyboard cursor.
              onMouseEnter={() => setCursor(index)}
              onClick={() => onPick(product)}
              tabIndex={-1}
            >
              <span className="browser-name">
                <span className="nm">{product.name}</span>
                <span className="sub">{product.barcode ?? product.code ?? '—'}</span>
              </span>
              <span className={`browser-qty ${out ? 'out' : ''}`}>
                {qty === null ? '—' : formatMoney(qty)}
              </span>
              <span className="browser-price">{formatMoney(product.price ?? 0)}</span>
            </button>
          )
        })}
      </div>
    </div>
  )
})
