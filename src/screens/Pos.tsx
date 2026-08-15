import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { db, type Client, type Currency, type DraftLine, type Product, type SaleDraft, type Unit } from '../db'
import { type MeResponse } from '../api'
import {
  addLine,
  closeDraft,
  createDraft,
  ensureDraft,
  setActiveDraftId,
  updateDraft,
} from '../drafts'
import { cartSubtotal, formatMoney, lineTotal, round2, submitSale, type CartLine, type Payment } from '../sales'
import { lastSyncAt, pendingCount, syncNow } from '../sync'
import { getTheme, setTheme, type Theme } from '../theme'
import { Checkout } from './Checkout'
import { ClientPicker } from './ClientPicker'
import { Clients, Products } from './Catalog'
import { Devices } from './Devices'
import { Drawer, type View } from './Drawer'
import { History } from './History'
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
 * Two structural decisions worth knowing:
 *
 * OPEN SALES ARE TABS. A counter serves more than one customer at a time — the
 * first is still deciding, the second is already putting things down. Each tab
 * is a row in IndexedDB, not React state, so a reload never discards a basket
 * somebody spent five minutes scanning.
 *
 * THE AREA UNDER THE SEARCH BOX IS THE SALE. Only what has been rung up. The
 * catalogue is reached by typing or scanning, and lives in the drawer for the
 * times someone genuinely wants to browse it.
 */
export function Pos({ me, onLogout }: { me: MeResponse; onLogout: () => void }) {
  const shopCurrency = me.shop.currency_id ?? 1

  const [view, setView] = useState<View>('sale')
  const [drawerOpen, setDrawerOpen] = useState(false)

  const [activeId, setActiveId] = useState<string | null>(null)
  const [checkout, setCheckout] = useState(false)
  const [clientPicker, setClientPicker] = useState(false)

  const [theme, setThemeState] = useState<Theme>(getTheme)
  const [toast, setToast] = useState<{ kind: 'ok' | 'err' | 'warn'; text: string } | null>(null)
  const [online, setOnline] = useState(navigator.onLine)
  const [pending, setPending] = useState(0)
  const [syncedAt, setSyncedAt] = useState<string | null>(null)
  const [syncing, setSyncing] = useState(false)

  const toastTimer = useRef<number | null>(null)
  /** Undo stacks, kept per tab so switching does not lose a tab's history. */
  const undoStacks = useRef<Map<string, DraftLine[][]>>(new Map())
  const [undoDepth, setUndoDepth] = useState(0)

  const drafts = useLiveQuery(() => db.drafts.orderBy('createdAt').toArray(), [], [] as SaleDraft[])
  const products = useLiveQuery(() => db.products.toArray(), [], [] as Product[])
  const units = useLiveQuery(() => db.units.toArray(), [], [] as Unit[])
  const currencies = useLiveQuery(() => db.currencies.toArray(), [], [] as Currency[])

  const productsById = useMemo(() => new Map(products.map((p) => [p.id, p])), [products])

  const active = useMemo(
    () => drafts.find((d) => d.id === activeId) ?? drafts[0] ?? null,
    [drafts, activeId],
  )

  useEffect(() => {
    void ensureDraft(shopCurrency).then(setActiveId)
  }, [shopCurrency])

  const unitName = useCallback(
    (id: number | null) => {
      const unit = units.find((u) => u.id === id)
      return unit?.short_name ?? unit?.name ?? ''
    },
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

  // ─── Draft mutation ───

  /** Every line change goes through here, so undo and persistence are automatic. */
  function mutateLines(next: (lines: DraftLine[]) => DraftLine[]) {
    if (!active) return

    const stack = undoStacks.current.get(active.id) ?? []
    undoStacks.current.set(active.id, [...stack.slice(-19), active.lines])
    setUndoDepth(undoStacks.current.get(active.id)!.length)

    void updateDraft(active.id, { lines: next(active.lines) })
  }

  function undo() {
    if (!active) return
    const stack = undoStacks.current.get(active.id) ?? []
    if (stack.length === 0) return

    const previous = stack[stack.length - 1]
    undoStacks.current.set(active.id, stack.slice(0, -1))
    setUndoDepth(stack.length - 1)
    void updateDraft(active.id, { lines: previous })
  }

  useEffect(() => {
    setUndoDepth(active ? (undoStacks.current.get(active.id) ?? []).length : 0)
  }, [active?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  async function newTab() {
    const created = await createDraft(shopCurrency)
    setActiveId(created.id)
    setView('sale')
  }

  async function closeTab(id: string) {
    const draft = drafts.find((d) => d.id === id)

    // A tab with items in it is work. Losing it to a stray click on a small ✕
    // is the kind of thing that makes a seller stop trusting the app.
    if (draft && draft.lines.length > 0) {
      const ok = confirm(
        `"${draft.name}" savdosida ${draft.lines.length} ta mahsulot bor.\n\nYopilsinmi?`,
      )
      if (!ok) return
    }

    undoStacks.current.delete(id)
    const next = await closeDraft(id)

    if (next === null) {
      const created = await createDraft(shopCurrency)
      setActiveId(created.id)
    } else {
      setActiveId(next)
    }
  }

  async function pickTab(id: string) {
    setActiveId(id)
    await setActiveDraftId(id)
  }

  // ─── Derived sale state ───

  const cart: CartLine[] = useMemo(() => {
    if (!active) return []

    return active.lines.map((line) => {
      const product = productsById.get(line.productId)

      return {
        // A product deleted from the catalogue mid-sale must not blank the row.
        // The line's own snapshot carries enough to finish and print it.
        product:
          product ??
          ({
            id: line.productId,
            name: line.name,
            code: null,
            barcode: null,
            price: line.price,
            quantity: null,
            unit_id: null,
            currency_id: null,
            supplier_id: null,
            low_stock_threshold: null,
            updated_at: null,
          } satisfies Product),
        quantity: line.quantity,
        price: line.price,
      }
    })
  }, [active, productsById])

  const subtotal = cartSubtotal(cart)

  const discount = useMemo(() => {
    if (!active) return 0
    const raw = Math.max(0, Number(active.discountValue) || 0)
    if (raw === 0) return 0
    // A percentage is of the basket; an amount is the amount. Capped either way
    // so a mistyped discount cannot produce a negative sale.
    const value = active.discountMode === 'percent' ? (subtotal * raw) / 100 : raw
    return round2(Math.min(value, subtotal))
  }, [active, subtotal])

  const total = round2(subtotal - discount)
  const currencyId = active?.currencyId ?? shopCurrency

  // ─── Actions ───

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
    if (!active) return

    const outcome = await submitSale(
      cart,
      { ...payment, discount, clientId: active.clientId },
      currencyId,
    )

    // The finished tab is closed rather than emptied: a seller who rang up a
    // sale is done with that customer, and an empty tab left behind would
    // accumulate one per sale over a day.
    undoStacks.current.delete(active.id)
    const next = await closeDraft(active.id)
    setActiveId(next ?? (await createDraft(shopCurrency)).id)

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

  const client: Pick<Client, 'id' | 'name' | 'phone_number'> | null = active?.clientId
    ? { id: active.clientId, name: active.clientName ?? '—', phone_number: null }
    : null

  return (
    <div className="app">
      <div className="topbar">
        <button
          className="ghost hamburger"
          onClick={() => setDrawerOpen((v) => !v)}
          aria-label="Menyu"
        >
          <span />
          <span />
          <span />
        </button>

        <span className="brand">pDaftar POS</span>
        <span className="meta">
          {me.shop.name} · {me.user.name ?? me.user.phone_number}
        </span>
        <span className="spacer" />

        <span className={`pill ${online ? 'online' : 'offline'}`}>
          {online ? '● Onlayn' : '● Oflayn'}
        </span>
        {pending > 0 && (
          <button className="pill queue" onClick={() => setView('queue')}>
            {pending} ta navbatda
          </button>
        )}

        <button
          className="ghost icon-btn"
          onClick={() => {
            const next: Theme = theme === 'dark' ? 'light' : 'dark'
            setTheme(next)
            setThemeState(next)
          }}
          title={theme === 'dark' ? "Yorug' rejim" : "Qorong'i rejim"}
          aria-label="Rejimni almashtirish"
        >
          {theme === 'dark' ? '☀' : '☾'}
        </button>

        <button className="primary" onClick={() => runSync()} disabled={syncing}>
          {syncing ? 'Sinxronlanmoqda…' : 'Sinxronlash'}
        </button>
      </div>

      <Drawer
        open={drawerOpen}
        view={view}
        me={me}
        pending={pending}
        onNavigate={(next) => {
          setView(next)
          setDrawerOpen(false)
        }}
        onClose={() => setDrawerOpen(false)}
        onLogout={onLogout}
      />

      {view === 'history' && <History />}
      {view === 'clients' && <Clients />}
      {view === 'products' && <Products />}
      {view === 'devices' && <Devices me={me} />}
      {view === 'queue' && <Queue onClose={() => setView('sale')} inline />}

      {view === 'sale' && (
        <div className="main">
          {/* ─── Sale ─── */}
          <div className="left">
            <div className="tabs">
              {drafts.map((draft) => {
                const count = draft.lines.length
                return (
                  <div
                    key={draft.id}
                    className={`tab ${draft.id === active?.id ? 'on' : ''}`}
                    onClick={() => void pickTab(draft.id)}
                  >
                    <span className="tab-name">{draft.name}</span>
                    {count > 0 && <span className="tab-count">{count}</span>}
                    <button
                      className="tab-x"
                      onClick={(e) => {
                        e.stopPropagation()
                        void closeTab(draft.id)
                      }}
                      aria-label="Yopish"
                    >
                      ✕
                    </button>
                  </div>
                )
              })}
              <button className="tab-new" onClick={() => void newTab()} title="Yangi savdo">
                + Yangi savdo
              </button>
            </div>

            <div className="search-row">
              <ProductSearch
                products={products}
                onPick={(product) => mutateLines((lines) => addLine(lines, product))}
                onMiss={(code) => say('err', `"${code}" bo'yicha mahsulot topilmadi`)}
              />
              <button
                className="ghost"
                onClick={undo}
                disabled={undoDepth === 0}
                title="Oxirgi amalni qaytarish"
              >
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
                      <div className="sub">
                        Kod: {line.product.code ?? line.product.barcode ?? '—'}
                      </div>
                      {oversell && (
                        // Warned, never blocked. The shop sells what it sells;
                        // the shortfall is recorded as negative stock, which is
                        // a visible problem — unlike a refused sale.
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
                            mutateLines((lines) =>
                              lines.map((l) =>
                                l.productId === line.product.id
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
                            mutateLines((lines) =>
                              lines.map((l) =>
                                l.productId === line.product.id
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
                          mutateLines((lines) => lines.filter((l) => l.productId !== line.product.id))
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
                    <span className="sub">Nasiya uchun tanlangan</span>
                  </span>
                  <button
                    className="ghost x danger"
                    onClick={() =>
                      active && void updateDraft(active.id, { clientId: null, clientName: null })
                    }
                    title="Mijozni olib tashlash"
                  >
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
              <select
                value={currencyId}
                onChange={(e) =>
                  active && void updateDraft(active.id, { currencyId: Number(e.target.value) })
                }
              >
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
                  value={active?.discountValue ?? ''}
                  onChange={(e) =>
                    active && void updateDraft(active.id, { discountValue: e.target.value })
                  }
                />
                <button
                  type="button"
                  className={active?.discountMode === 'percent' ? 'on' : ''}
                  onClick={() => active && void updateDraft(active.id, { discountMode: 'percent' })}
                >
                  %
                </button>
                <button
                  type="button"
                  className={active?.discountMode === 'amount' ? 'on' : ''}
                  onClick={() => active && void updateDraft(active.id, { discountMode: 'amount' })}
                >
                  {currencyCode(currencyId) || 'SUM'}
                </button>
              </div>
              <div className="seg" style={{ marginTop: 8 }}>
                {QUICK_DISCOUNTS.map((p) => (
                  <button
                    key={p}
                    type="button"
                    onClick={() =>
                      active &&
                      void updateDraft(active.id, {
                        discountMode: 'percent',
                        discountValue: String(p),
                      })
                    }
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
                <div className="row" style={{ color: 'var(--warn-text)' }}>
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
                onClick={() => active && void closeTab(active.id)}
                disabled={!active}
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
      )}

      {checkout && (
        <Checkout
          total={total}
          clientId={active?.clientId ?? null}
          onCancel={() => setCheckout(false)}
          onConfirm={confirmSale}
        />
      )}
      {clientPicker && (
        <ClientPicker
          onPick={(picked) => {
            if (active) {
              void updateDraft(active.id, {
                clientId: picked?.id ?? null,
                clientName: picked?.name ?? null,
              })
            }
            setClientPicker(false)
          }}
          onClose={() => setClientPicker(false)}
        />
      )}
    </div>
  )
}
