import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { db, type Client, type Currency, type Product, type Unit } from '../db'
import { type MeResponse } from '../api'
import {
  cartSubtotal,
  formatMoney,
  lineTotal,
  round2,
  submitSale,
  type CartLine,
  type Payment,
} from '../sales'
import { lastSyncAt, pendingCount, syncNow } from '../sync'
import { Checkout } from './Checkout'
import { ClientPicker } from './ClientPicker'
import { ProductSearch } from './ProductSearch'
import { Queue } from './Queue'

const QUICK_DISCOUNTS = [5, 10, 15, 20]

/**
 * The till.
 *
 * Everything on screen is driven off IndexedDB via useLiveQuery, never off a
 * fetch. That is what makes "the internet went away" a non-event: nothing here
 * waits on the network, because nothing here reads from it.
 *
 * Layout follows the shape pDaftar's own sale screen already uses, and for the
 * same reason: the area under the search box is the SALE — only what has been
 * rung up. The catalogue is not a wall of tiles to hunt through; it is reached
 * by typing or scanning, and matches appear over the box. The sidebar carries
 * the decisions that apply to the whole sale — who it is for, in what currency,
 * with what discount.
 */
export function Pos({ me, onLogout }: { me: MeResponse; onLogout: () => void }) {
  const [cart, setCart] = useState<CartLine[]>([])
  const [history, setHistory] = useState<CartLine[][]>([])

  const [client, setClient] = useState<Client | null>(null)
  const [currencyId, setCurrencyId] = useState<number>(me.shop.currency_id ?? 1)
  const [discountValue, setDiscountValue] = useState('')
  const [discountMode, setDiscountMode] = useState<'percent' | 'amount'>('percent')

  const [checkout, setCheckout] = useState(false)
  const [clientPicker, setClientPicker] = useState(false)
  const [queueOpen, setQueueOpen] = useState(false)

  const [toast, setToast] = useState<{ kind: 'ok' | 'err' | 'warn'; text: string } | null>(null)
  const [online, setOnline] = useState(navigator.onLine)
  const [pending, setPending] = useState(0)
  const [syncedAt, setSyncedAt] = useState<string | null>(null)
  const [syncing, setSyncing] = useState(false)

  const toastTimer = useRef<number | null>(null)

  const products = useLiveQuery(() => db.products.toArray(), [], [] as Product[])
  const units = useLiveQuery(() => db.units.toArray(), [], [] as Unit[])
  const currencies = useLiveQuery(() => db.currencies.toArray(), [], [] as Currency[])

  const unitName = useCallback(
    (id: number | null) => units.find((u) => u.id === id)?.short_name ?? units.find((u) => u.id === id)?.name ?? '',
    [units],
  )
  const currencyCode = useCallback(
    (id: number) => currencies.find((c) => c.id === id)?.code ?? '',
    [currencies],
  )

  function say(kind: 'ok' | 'err' | 'warn', text: string) {
    setToast({ kind, text })
    if (toastTimer.current) window.clearTimeout(toastTimer.current)
    toastTimer.current = window.setTimeout(() => setToast(null), 6000)
  }

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

  // Sweep the outbox whenever the connection returns. A seller who was offline
  // for an hour should not have to remember to press a button.
  useEffect(() => {
    if (!online) return
    void runSync(true)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [online])

  /** Snapshot before every mutation, so "Qaytarish" can undo the last one. */
  function mutate(next: (current: CartLine[]) => CartLine[]) {
    setCart((current) => {
      setHistory((h) => [...h.slice(-19), current])
      return next(current)
    })
  }

  function undo() {
    setHistory((h) => {
      if (h.length === 0) return h
      setCart(h[h.length - 1])
      return h.slice(0, -1)
    })
  }

  function addProduct(product: Product) {
    mutate((current) => {
      const existing = current.find((line) => line.product.id === product.id)
      if (existing) {
        return current.map((line) =>
          line.product.id === product.id ? { ...line, quantity: round2(line.quantity + 1) } : line,
        )
      }
      return [...current, { product, quantity: 1, price: product.price ?? 0 }]
    })
  }

  const subtotal = cartSubtotal(cart)

  const discount = useMemo(() => {
    const raw = Math.max(0, Number(discountValue) || 0)
    if (raw === 0) return 0
    // A percentage is of the basket; an amount is the amount. Capped either way
    // so a mistyped discount cannot produce a negative sale.
    const value = discountMode === 'percent' ? (subtotal * raw) / 100 : raw
    return round2(Math.min(value, subtotal))
  }, [discountValue, discountMode, subtotal])

  const total = round2(subtotal - discount)

  async function runSync(silent = false) {
    setSyncing(true)
    try {
      const report = await syncNow()
      if (!silent || report.pushed > 0 || report.errored > 0) {
        say(report.errored > 0 ? 'warn' : 'ok', report.message)
      }
    } catch (e) {
      if (!silent) say('err', e instanceof Error ? e.message : 'Sinxronlashda xatolik')
    } finally {
      setSyncing(false)
      setPending(await pendingCount())
      setSyncedAt(await lastSyncAt())
    }
  }

  async function confirmSale(payment: Omit<Payment, 'discount' | 'clientId'>) {
    const outcome = await submitSale(
      cart,
      { ...payment, discount, clientId: client?.id ?? null },
      currencyId,
    )

    setCart([])
    setHistory([])
    setDiscountValue('')
    setClient(null)
    setCheckout(false)
    setPending(await pendingCount())

    if (outcome.synced) {
      const change = outcome.change > 0 ? ` · Qaytim: ${formatMoney(outcome.change)}` : ''
      say('ok', `Sotuv yozildi: ${formatMoney(outcome.total)}${change}`)
    } else {
      // Not an error. The sale is durable in the outbox; it just has not
      // reached the server yet.
      say(
        'warn',
        `Sotuv navbatga qo'yildi (${formatMoney(outcome.total)}). Internet paydo bo'lganda yuboriladi.`,
      )
    }
  }

  return (
    <div className="app">
      <div className="topbar">
        <span className="brand">pDaftar POS</span>
        <span className="meta">
          {me.shop.name} · {me.user.name ?? me.user.phone_number}
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
        {/* ─── Sale ─── */}
        <div className="left">
          <div className="search-row">
            <ProductSearch
              products={products}
              onPick={addProduct}
              onMiss={(code) => say('err', `"${code}" bo'yicha mahsulot topilmadi`)}
            />
            <button className="ghost" onClick={undo} disabled={history.length === 0} title="Oxirgi amalni qaytarish">
              ↺ Qaytarish
            </button>
          </div>

          {toast && (
            <div className={`notice ${toast.kind}`} onClick={() => setToast(null)}>
              {toast.text}
            </div>
          )}

          <div className="cart-head-row">
            <span>MAHSULOT</span>
            <span className="c">MIQDORI</span>
            <span className="c">NARXI</span>
            <span className="r">JAMI</span>
            <span />
          </div>

          <div className="cart-body">
            {cart.length === 0 && (
              <div className="cart-empty">
                Barcode skanerlang yoki yuqoridan mahsulot qidiring
                <div className="hint" style={{ marginTop: 8 }}>
                  Tanlangan mahsulotlar shu yerda ko'rinadi
                </div>
              </div>
            )}

            {cart.map((line) => {
              const stock = line.product.quantity
              const oversell = stock !== null && line.quantity > stock

              return (
                <div className="cart-row" key={line.product.id}>
                  <div className="cell name">
                    <div className="nm">{line.product.name}</div>
                    <div className="sub">Kod: {line.product.code ?? line.product.barcode ?? '—'}</div>
                    {oversell && (
                      // Warned, never blocked. The shop sells what it sells;
                      // the shortfall is recorded as negative stock, which is a
                      // visible problem — unlike a refused sale.
                      <div className="warn">
                        Qoldiqdan ko'p ({formatMoney(stock!)} bor) — minusga tushadi
                      </div>
                    )}
                  </div>

                  <div className="cell c">
                    <div className="stepper">
                      <input
                        type="number"
                        min="0.01"
                        step="any"
                        value={line.quantity}
                        onChange={(e) =>
                          mutate((c) =>
                            c.map((l) =>
                              l.product.id === line.product.id
                                ? { ...l, quantity: Number(e.target.value) || 0 }
                                : l,
                            ),
                          )
                        }
                      />
                      <span className="unit">{unitName(line.product.unit_id)}</span>
                    </div>
                  </div>

                  <div className="cell c">
                    <div className="stepper">
                      <input
                        type="number"
                        min="0"
                        step="any"
                        value={line.price}
                        onChange={(e) =>
                          mutate((c) =>
                            c.map((l) =>
                              l.product.id === line.product.id
                                ? { ...l, price: Number(e.target.value) || 0 }
                                : l,
                            ),
                          )
                        }
                      />
                      <span className="unit">{currencyCode(currencyId)}</span>
                    </div>
                  </div>

                  <div className="cell r sum">{formatMoney(lineTotal(line))}</div>

                  <div className="cell">
                    <button
                      className="ghost x"
                      onClick={() =>
                        mutate((c) => c.filter((l) => l.product.id !== line.product.id))
                      }
                    >
                      ✕
                    </button>
                  </div>
                </div>
              )
            })}
          </div>
        </div>

        {/* ─── Sale-wide decisions ─── */}
        <div className="right">
          <div className="side-client">
            {client ? (
              <>
                <span className="avatar">{client.name.charAt(0).toUpperCase()}</span>
                <span className="grow">
                  <span className="nm">{client.name}</span>
                  <span className="sub">{client.phone_number ?? 'Telefon yo\'q'}</span>
                </span>
                <button className="ghost x danger" onClick={() => setClient(null)} title="Mijozni olib tashlash">
                  ✕
                </button>
              </>
            ) : (
              <button className="ghost" style={{ width: '100%' }} onClick={() => setClientPicker(true)}>
                + Mijoz tanlash
              </button>
            )}
          </div>

          <div className="side-block">
            <div className="side-label">VALYUTA</div>
            <select value={currencyId} onChange={(e) => setCurrencyId(Number(e.target.value))}>
              {currencies.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.code ?? c.name}
                </option>
              ))}
            </select>
          </div>

          <div className="side-block">
            <div className="side-label">CHEGIRMA</div>
            <div className="discount-row">
              <input
                type="number"
                min="0"
                placeholder="Miqdor…"
                value={discountValue}
                onChange={(e) => setDiscountValue(e.target.value)}
              />
              <button
                type="button"
                className={discountMode === 'percent' ? 'on' : ''}
                onClick={() => setDiscountMode('percent')}
              >
                %
              </button>
              <button
                type="button"
                className={discountMode === 'amount' ? 'on' : ''}
                onClick={() => setDiscountMode('amount')}
              >
                {currencyCode(currencyId) || 'SUM'}
              </button>
            </div>
            <div className="seg" style={{ marginTop: 8 }}>
              {QUICK_DISCOUNTS.map((p) => (
                <button
                  key={p}
                  type="button"
                  onClick={() => {
                    setDiscountMode('percent')
                    setDiscountValue(String(p))
                  }}
                >
                  {p}%
                </button>
              ))}
            </div>
          </div>

          <div className="side-spacer" />

          <div className="totals">
            <div className="row">
              <span className="muted">{cart.length} ta qator</span>
              <span className="muted">
                {formatMoney(cart.reduce((n, l) => n + l.quantity, 0))} dona
              </span>
            </div>
            <div className="row">
              <span className="muted">Jami</span>
              <span>{formatMoney(subtotal)}</span>
            </div>
            {discount > 0 && (
              <div className="row" style={{ color: '#f0cf8a' }}>
                <span>Chegirma</span>
                <span>− {formatMoney(discount)}</span>
              </div>
            )}
            <div className="row grand">
              <span>To'lash</span>
              <span>{formatMoney(total)}</span>
            </div>
          </div>

          <div className="actions">
            <button
              className="ghost"
              onClick={() => {
                mutate(() => [])
                setDiscountValue('')
                setClient(null)
              }}
              disabled={cart.length === 0}
            >
              Bekor qilish
            </button>
            <button className="primary" disabled={cart.length === 0} onClick={() => setCheckout(true)}>
              To'lov qilish: {formatMoney(total)} {currencyCode(currencyId)}
            </button>
          </div>

          {syncedAt && (
            <div className="side-foot">
              Oxirgi sinxronlash: {new Date(syncedAt).toLocaleString('uz-UZ')}
            </div>
          )}
        </div>
      </div>

      {checkout && (
        <Checkout
          total={total}
          clientId={client?.id ?? null}
          onCancel={() => setCheckout(false)}
          onConfirm={confirmSale}
        />
      )}
      {clientPicker && (
        <ClientPicker
          onPick={(picked) => {
            setClient(picked)
            setClientPicker(false)
          }}
          onClose={() => setClientPicker(false)}
        />
      )}
      {queueOpen && <Queue onClose={() => setQueueOpen(false)} />}
    </div>
  )
}
