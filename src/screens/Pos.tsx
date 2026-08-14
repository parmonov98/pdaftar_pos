import { useEffect, useMemo, useRef, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { db, type Product } from '../db'
import { type MeResponse } from '../api'
import { cartSubtotal, formatMoney, lineTotal, submitSale, type CartLine, type Payment } from '../sales'
import { lastSyncAt, pendingCount, syncNow } from '../sync'
import { Checkout } from './Checkout'
import { Queue } from './Queue'

/**
 * The till.
 *
 * The whole screen is driven off IndexedDB via useLiveQuery, not off fetches.
 * That is what makes "the internet went away" a non-event: nothing on this
 * screen ever waits for the network, because nothing on it reads from the
 * network.
 */
export function Pos({ me, onLogout }: { me: MeResponse; onLogout: () => void }) {
  const [query, setQuery] = useState('')
  const [cart, setCart] = useState<CartLine[]>([])
  const [checkout, setCheckout] = useState(false)
  const [queueOpen, setQueueOpen] = useState(false)
  const [toast, setToast] = useState<{ kind: 'ok' | 'err' | 'warn'; text: string } | null>(null)
  const [online, setOnline] = useState(navigator.onLine)
  const [pending, setPending] = useState(0)
  const [syncedAt, setSyncedAt] = useState<string | null>(null)
  const [syncing, setSyncing] = useState(false)

  const scanRef = useRef<HTMLInputElement>(null)

  const products = useLiveQuery(() => db.products.toArray(), [], [] as Product[])

  useEffect(() => {
    const goOnline = () => setOnline(true)
    const goOffline = () => setOnline(false)
    window.addEventListener('online', goOnline)
    window.addEventListener('offline', goOffline)
    return () => {
      window.removeEventListener('online', goOnline)
      window.removeEventListener('offline', goOffline)
    }
  }, [])

  useEffect(() => {
    const refresh = () => {
      void pendingCount().then(setPending)
      void lastSyncAt().then(setSyncedAt)
    }
    refresh()
    const timer = setInterval(refresh, 3000)
    return () => clearInterval(timer)
  }, [])

  // Sweep the outbox whenever the connection comes back. A cashier who was
  // offline for an hour should not have to remember to press a button.
  useEffect(() => {
    if (!online) return
    void runSync(true)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [online])

  const matches = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const alive = products.filter((p) => !p.deleted)
    if (needle === '') return alive.slice(0, 60)

    return alive
      .filter(
        (p) =>
          p.barcode?.toLowerCase() === needle ||
          p.code?.toLowerCase() === needle ||
          (p.name ?? '').toLowerCase().includes(needle),
      )
      .slice(0, 60)
  }, [products, query])

  function addToCart(product: Product) {
    setCart((current) => {
      const existing = current.find((line) => line.product.id === product.id)
      if (existing) {
        return current.map((line) =>
          line.product.id === product.id ? { ...line, quantity: line.quantity + 1 } : line,
        )
      }
      return [...current, { product, quantity: 1, price: product.price ?? 0 }]
    })
    setQuery('')
    scanRef.current?.focus()
  }

  /**
   * Scanner input. A barcode reader types the code and presses Enter, so an
   * exact barcode/code match adds the item immediately — the cashier never
   * touches the list. A name search falls through to the grid.
   */
  function onScanSubmit(event: React.FormEvent) {
    event.preventDefault()
    const needle = query.trim().toLowerCase()
    if (needle === '') return

    const exact = products.find(
      (p) => !p.deleted && (p.barcode?.toLowerCase() === needle || p.code?.toLowerCase() === needle),
    )

    if (exact) {
      addToCart(exact)
      return
    }

    if (matches.length === 1) {
      addToCart(matches[0])
      return
    }

    if (matches.length === 0) {
      setToast({ kind: 'err', text: `"${query}" bo'yicha mahsulot topilmadi` })
    }
  }

  function setQuantity(productId: number, quantity: number) {
    setCart((current) =>
      current.map((line) => (line.product.id === productId ? { ...line, quantity } : line)),
    )
  }

  function setPrice(productId: number, price: number) {
    setCart((current) =>
      current.map((line) => (line.product.id === productId ? { ...line, price } : line)),
    )
  }

  function removeLine(productId: number) {
    setCart((current) => current.filter((line) => line.product.id !== productId))
  }

  async function runSync(silent = false) {
    setSyncing(true)
    try {
      const report = await syncNow()
      if (!silent || report.pushed > 0 || report.errored > 0) {
        setToast({ kind: report.errored > 0 ? 'warn' : 'ok', text: report.message })
      }
    } catch (e) {
      if (!silent) {
        setToast({ kind: 'err', text: e instanceof Error ? e.message : 'Sinxronlashda xatolik' })
      }
    } finally {
      setSyncing(false)
      setPending(await pendingCount())
      setSyncedAt(await lastSyncAt())
    }
  }

  async function confirmSale(payment: Payment) {
    const outcome = await submitSale(cart, payment, me.shop.currency_id ?? 1)

    setCart([])
    setCheckout(false)
    setPending(await pendingCount())

    if (outcome.synced) {
      const change = outcome.change > 0 ? ` · Qaytim: ${formatMoney(outcome.change)}` : ''
      setToast({ kind: 'ok', text: `Sotuv yozildi: ${formatMoney(outcome.total)}${change}` })
    } else {
      // Not an error. The sale is durable in the outbox; it just has not
      // reached the server yet.
      setToast({
        kind: 'warn',
        text: `Sotuv navbatga qo'yildi (${formatMoney(outcome.total)}). Internet paydo bo'lganda yuboriladi.`,
      })
    }

    scanRef.current?.focus()
  }

  const subtotal = cartSubtotal(cart)

  return (
    <div className="app">
      <div className="topbar">
        <span className="brand">pDaftar POS</span>
        <span className="meta">
          {me.shop.name} · {me.terminal.name}
        </span>
        <span className="spacer" />

        <span className={`pill ${online ? 'online' : 'offline'}`}>
          {online ? '● Onlayn' : '● Oflayn'}
        </span>

        {pending > 0 && <span className="pill queue">{pending} ta navbatda</span>}

        <button className="ghost" onClick={() => setQueueOpen(true)}>
          Navbat
        </button>
        <button className="primary" onClick={() => runSync()} disabled={syncing}>
          {syncing ? 'Sinxronlanmoqda…' : 'Sinxronlash'}
        </button>
        <button className="ghost" onClick={onLogout}>
          Chiqish
        </button>
      </div>

      <div className="main">
        <div className="left">
          <form className="scan-row" onSubmit={onScanSubmit}>
            <input
              ref={scanRef}
              autoFocus
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Barcode skanerlang yoki nomi bo'yicha qidiring…"
            />
            <button className="primary" type="submit">
              Qo'shish
            </button>
          </form>

          {toast && (
            <div className={`notice ${toast.kind}`} onClick={() => setToast(null)}>
              {toast.text}
            </div>
          )}

          <div className="grid">
            {matches.map((product) => {
              const qty = product.quantity
              // null means the product was never inventoried — deliberately
              // rendered as "—", not as 0. Showing 0 would flag most of the
              // catalogue as out of stock (see the backend's StockService).
              const stockClass =
                qty === null ? '' : qty <= 0 ? 'out' : qty <= (product.low_stock_threshold ?? 0) ? 'low' : ''

              return (
                <button className="card" key={product.id} onClick={() => addToCart(product)}>
                  <span className="name">{product.name}</span>
                  <span className="price">{formatMoney(product.price ?? 0)}</span>
                  <span className={`stock ${stockClass}`}>
                    Qoldiq: {qty === null ? '—' : formatMoney(qty)}
                  </span>
                </button>
              )
            })}
            {matches.length === 0 && (
              <div className="cart-empty" style={{ gridColumn: '1 / -1' }}>
                {products.length === 0
                  ? 'Katalog bo\'sh. "Sinxronlash" tugmasini bosing.'
                  : 'Mahsulot topilmadi'}
              </div>
            )}
          </div>
        </div>

        <div className="right">
          <div className="cart-head">
            <strong>Savatcha</strong>
            {cart.length > 0 && (
              <button className="ghost" onClick={() => setCart([])}>
                Tozalash
              </button>
            )}
          </div>

          <div className="cart-lines">
            {cart.length === 0 && <div className="cart-empty">Mahsulot qo'shing</div>}

            {cart.map((line) => {
              const stock = line.product.quantity
              const oversell = stock !== null && line.quantity > stock

              return (
                <div className="line" key={line.product.id}>
                  <div className="top">
                    <span className="nm">{line.product.name}</span>
                    <button className="ghost" onClick={() => removeLine(line.product.id)}>
                      ✕
                    </button>
                  </div>
                  <div className="controls">
                    <input
                      type="number"
                      min="0.01"
                      step="any"
                      value={line.quantity}
                      onChange={(e) => setQuantity(line.product.id, Number(e.target.value) || 0)}
                    />
                    <span className="x">×</span>
                    <input
                      type="number"
                      min="0"
                      step="any"
                      value={line.price}
                      onChange={(e) => setPrice(line.product.id, Number(e.target.value) || 0)}
                    />
                    <span className="sum">{formatMoney(lineTotal(line))}</span>
                  </div>
                  {oversell && (
                    // Warned, never blocked. The shop sells what it sells; the
                    // shortfall is recorded as negative stock and reconciled
                    // later, which is visible — unlike a refused sale.
                    <div className="warn">
                      Qoldiqdan ko'p ({formatMoney(stock!)} bor) — minusga tushadi
                    </div>
                  )}
                </div>
              )
            })}
          </div>

          <div className="totals">
            <div className="row">
              <span className="muted">{cart.length} ta qator</span>
              <span className="muted">
                {formatMoney(cart.reduce((n, l) => n + l.quantity, 0))} dona
              </span>
            </div>
            <div className="row grand">
              <span>Jami</span>
              <span>{formatMoney(subtotal)}</span>
            </div>
          </div>

          <div className="actions">
            <button className="primary" disabled={cart.length === 0} onClick={() => setCheckout(true)}>
              To'lovga o'tish
            </button>
          </div>

          {syncedAt && (
            <div style={{ padding: '0 14px 12px', fontSize: 12, color: 'var(--muted)' }}>
              Oxirgi sinxronlash: {new Date(syncedAt).toLocaleString('uz-UZ')}
            </div>
          )}
        </div>
      </div>

      {checkout && (
        <Checkout lines={cart} onCancel={() => setCheckout(false)} onConfirm={confirmSale} />
      )}
      {queueOpen && <Queue onClose={() => setQueueOpen(false)} />}
    </div>
  )
}
