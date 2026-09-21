import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import {
  db,
  type Client,
  type Currency,
  type DraftLine,
  type Product,
  type ProductUnitOption,
  type SaleDraft,
  type Unit,
} from '../db'
import { type MeResponse } from '../api'
import {
  addLine,
  priceFor,
  closeDraft,
  createDraft,
  ensureDraft,
  setActiveDraftId,
  updateDraft,
} from '../drafts'
import {
  formatMoney,
  lineTotal,
  round2,
  subtotalsByCurrency,
  submitSale,
  type CartLine,
  type Payment,
} from '../sales'
import { lastSyncAt, pendingCount, syncNow } from '../sync'
import { getTheme, setTheme, type Theme } from '../theme'
import { Checkout } from './Checkout'
import { ClientPicker } from './ClientPicker'
import { Clients, Products } from './Catalog'
import { Devices } from './Devices'
import { Drawer, type View } from './Drawer'
import { keyOwner } from '../keys'
import { sortRows, type SortState } from '../sorting'
import { SortHeader } from './SortHeader'
import { toast, type ToastKind } from '../toast'
import { History } from './History'
import { ProductBrowser, type BrowserHandle } from './ProductBrowser'
import { ProductSearch } from './ProductSearch'
import { Queue } from './Queue'
import { ReceiptView } from './Receipt'
import { attachReceipt, receiptFromSale, type Receipt } from '../receipt'

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
const SPLIT_KEY = 'pos.split_dir'

/** The columns the basket can be ordered by. */
type CartCol = 'name' | 'qty' | 'price' | 'total'

/** "×12", or "12/5" when the ratio is not whole. Empty for the base unit. */
function conversionLabel(unit: ProductUnitOption): string {
  if (unit.numerator === 1 && unit.denominator === 1) return ''
  if (unit.denominator === 1) return `×${unit.numerator}`
  return `${unit.numerator}/${unit.denominator}`
}

export function Pos({ me, onLogout }: { me: MeResponse; onLogout: () => void }) {
  const shopCurrency = me.shop.currency_id ?? 1

  const [view, setView] = useState<View>('sale')
  const [drawerOpen, setDrawerOpen] = useState(false)

  const [activeId, setActiveId] = useState<string | null>(null)
  const [checkout, setCheckout] = useState(false)

  /**
   * Whether the sale-wide controls are showing, on a phone.
   *
   * They are always open on a desktop till, where there is a column to spare.
   * On a 375pt phone they were taking 427 of 812 points and leaving the cart
   * — the screen a cashier actually works in — with 178. Customer, currency
   * and discount are decided once per sale at most; the basket is touched on
   * every line, so it gets the room by default.
   */
  const [sideOpen, setSideOpen] = useState(false)

  /**
   * How the basket is ordered on screen.
   *
   * null — and it starts null — means scan order, which is the order the
   * cashier put things down in and the order the customer watched them go
   * in. Sorting is a VIEW: the draft keeps its own order, so clicking a
   * column back to off restores it exactly.
   */
  const [cartSort, setCartSort] = useState<SortState<CartCol>>(null)
  const [clientPicker, setClientPicker] = useState(false)
  const [receipt, setReceipt] = useState<Receipt | null>(null)
  /** The other currencies' slips, printed with the first. */
  const [moreReceipts, setMoreReceipts] = useState<Receipt[]>([])

  const [theme, setThemeState] = useState<Theme>(getTheme)
  const [online, setOnline] = useState(navigator.onLine)
  const [pending, setPending] = useState(0)
  const [syncedAt, setSyncedAt] = useState<string | null>(null)
  const [syncing, setSyncing] = useState(false)

  /** Undo stacks, kept per tab so switching does not lose a tab's history. */
  const undoStacks = useRef<Map<string, DraftLine[][]>>(new Map())
  const [undoDepth, setUndoDepth] = useState(0)

  /*
   * Two panes, and which of them owns the keyboard.
   *
   * The orientation is the cashier's choice because the hardware is not ours
   * to predict: a 1920-wide monoblok wants the list beside the basket, a
   * 1024x768 one stacked. Remembered per device — it is a property of the
   * counter, not of the account.
   */
  const [splitDir, setSplitDir] = useState<'vertical' | 'horizontal'>(() => {
    try {
      return localStorage.getItem(SPLIT_KEY) === 'horizontal' ? 'horizontal' : 'vertical'
    } catch {
      return 'vertical'
    }
  })
  const [pane, setPane] = useState<'browser' | 'cart'>('browser')
  const [cartCursor, setCartCursor] = useState(0)
  const cartPaneRef = useRef<HTMLDivElement>(null)
  const browserRef = useRef<BrowserHandle>(null)

  function flipSplit() {
    setSplitDir((d) => {
      const next = d === 'vertical' ? 'horizontal' : 'vertical'
      try {
        localStorage.setItem(SPLIT_KEY, next)
      } catch {
        // A till with storage blocked still splits, it just forgets.
      }
      return next
    })
  }

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

  /**
   * Say something that happened.
   *
   * The message goes to the toast stack, which lives outside this screen's
   * layout — it used to be rendered inline here, and every "Sotuv yozildi"
   * shoved the basket down the moment the cashier was reaching into it.
   */
  function say(kind: ToastKind, text: string) {
    toast(kind, text)
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

  const cartLines: CartLine[] = useMemo(() => {
    if (!active) return []

    return active.lines.map((line) => {
      const product = productsById.get(line.productId)

      return {
        productUnitId: line.productUnitId ?? null,
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
        // The line's own currency, falling back to the tab's for lines
        // written before a line could have one of its own.
        currencyId: line.currencyId ?? active.currencyId,
      }
    })
  }, [active, productsById])

  // Sorted for display AND for the keyboard, from one array — the arrow keys
  // walk what the eye sees, or Delete removes a different line from the one
  // the cursor is on.
  const cart: CartLine[] = useMemo(
    () =>
      sortRows(cartLines, cartSort, (line, key) => {
        if (key === 'name') return line.product.name
        if (key === 'qty') return line.quantity
        if (key === 'price') return line.price
        return lineTotal(line)
      }),
    [cartLines, cartSort],
  )

  // Switching to another sale tab starts it in its own scan order rather
  // than inheriting a sort chosen for a different basket.
  useEffect(() => {
    setCartSort(null)
  }, [activeId])

  /*
   * The whole till, on the keyboard.
   *
   * A till frequently has no mouse, and where it has one a cashier with a
   * queue does not reach for it: the hand is on the keys or the scanner.
   * Function keys rather than letter chords, because the scanner types
   * letters — a barcode containing "p" must not fire a shortcut.
   *
   * Everywhere:
   *   F2        open the menu (Tarix, Mijozlar, Navbat, …)
   *   F9        Sinxronlash
   *   Esc       back to the sale screen
   *
   * On the sale screen:
   *   F3        find a product
   *   F4        take payment
   *   F7        choose the customer
   *   F8        flip the split
   *   Tab       move between the two panes
   *   ↑ ↓       move in the focused pane
   *   Enter     add the highlighted product / edit the highlighted line
   *   + −       change the highlighted line's quantity
   *   Delete    remove the line
   *   Esc       clear, then step back
   */
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      // Never steal a key from a dialog: the checkout has its own Enter.
      if (checkout) return

      const target = event.target as HTMLElement | null
      const typing = target?.tagName === 'INPUT' || target?.tagName === 'SELECT' || target?.tagName === 'TEXTAREA'

      // ─── Who owns this key ───
      //
      // One rule, stated in keys.ts and tested there. Inline, it was answered
      // differently in each branch and the sale screen's bindings leaked onto
      // every other screen.
      const owner = keyOwner({
        key: event.key,
        view,
        drawerOpen,
        dialogOpen: checkout,
        typing,
      })

      // ─── Keys that work on every screen ───
      //
      // Without these the menu was mouse-only, and the menu is the only way
      // to Tarix, Mijozlar, Mahsulotlar, Navbat and Qurilmalar: a keyboard
      // user could ring up sales and reach nothing else in the product.
      if (owner === 'global') {
        event.preventDefault()

        if (event.key === 'F2') setDrawerOpen((open) => !open)
        else if (event.key === 'F9') { if (!syncing) void runSync() }
        // Out of the menu first, then out of the screen it opened.
        else if (drawerOpen) setDrawerOpen(false)
        else setView('sale')

        return
      }

      // ─── Everything below belongs to the sale screen ───
      if (owner !== 'sale') return

      if (event.key === 'F3') {
        event.preventDefault()
        setPane('browser')
        browserRef.current?.focus()
        return
      }

      if (event.key === 'F4') {
        event.preventDefault()
        openCheckout()
        return
      }

      if (event.key === 'F8') {
        event.preventDefault()
        flipSplit()
        return
      }

      if (event.key === 'F7') {
        event.preventDefault()
        setClientPicker(true)
        return
      }

      // F6 — the highlighted line's unit, from the keyboard. The picker was
      // a mouse-only control on a screen whose whole point is that it is
      // not, so a till without a mouse could ring up bottles and never
      // boxes.
      if (event.key === 'F6') {
        event.preventDefault()
        const line = cart[cartCursor]
        if (!line) return

        const units = (line.product.units ?? []).filter((u) => u.is_active)
        if (units.length < 2) return

        const at = units.findIndex((u) => u.id === line.productUnitId)
        setPane('cart')
        setLineUnit(line.product.id, units[(at + 1) % units.length].id)
        return
      }

      // Forward Tab moves between the two work panes. Shift+Tab is left
      // alone on purpose: it is the way OUT of the work area, to the tab
      // strip, the top bar and the sale-wide controls. Swallowing both left
      // the cashier cycling between two panes with no exit.
      if (event.key === 'Tab' && !event.shiftKey && pane === 'cart' && !typing) {
        event.preventDefault()
        setPane('browser')
        return
      }

      // The product pane. Handled here as well as on its own input, so the
      // arrows work whether or not the search box happens to hold focus —
      // the browser stops propagation for the keys it has already handled.
      if (pane === 'browser') {
        if (event.key === 'ArrowDown') {
          event.preventDefault()
          browserRef.current?.move(1)
        } else if (event.key === 'ArrowUp') {
          event.preventDefault()
          browserRef.current?.move(-1)
        } else if (event.key === 'PageDown') {
          event.preventDefault()
          browserRef.current?.move(10)
        } else if (event.key === 'PageUp') {
          event.preventDefault()
          browserRef.current?.move(-10)
        } else if (event.key === 'Enter') {
          event.preventDefault()
          browserRef.current?.pickCurrent()
        } else if (event.key === 'Tab' && !event.shiftKey) {
          event.preventDefault()
          setPane('cart')
        }

        return
      }

      // Everything below belongs to the cart, and only while it has focus.
      if (pane !== 'cart' || typing) return

      if (event.key === 'ArrowDown') {
        event.preventDefault()
        setCartCursor((c) => Math.min(c + 1, cart.length - 1))
        return
      }

      if (event.key === 'ArrowUp') {
        event.preventDefault()
        setCartCursor((c) => Math.max(c - 1, 0))
        return
      }

      const line = cart[cartCursor]
      if (!line) return

      if (event.key === '+' || event.key === '=') {
        event.preventDefault()
        mutateLines((lines) =>
          lines.map((l) => (l.productId === line.product.id ? { ...l, quantity: l.quantity + 1 } : l)),
        )
        return
      }

      if (event.key === '-') {
        event.preventDefault()
        // Down to one, not to zero: removing is Delete, and a line that
        // vanished because the key repeated is a sale quietly short an item.
        mutateLines((lines) =>
          lines.map((l) =>
            l.productId === line.product.id ? { ...l, quantity: Math.max(1, l.quantity - 1) } : l,
          ),
        )
        return
      }

      if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault()
        mutateLines((lines) => lines.filter((l) => l.productId !== line.product.id))
        setCartCursor((c) => Math.max(0, Math.min(c, cart.length - 2)))
      }
    }

    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
    // mutateLines is redefined every render, so it is deliberately not a
    // dependency — the effect re-subscribes often enough on the state it does
    // list, and each run closes over a current copy.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [checkout, pane, cart, cartCursor, view, drawerOpen, syncing])

  // A line removed under the cursor must not leave it pointing past the end.
  useEffect(() => {
    setCartCursor((c) => Math.max(0, Math.min(c, cart.length - 1)))
  }, [cart.length])


  /** Subtotal per currency — the basket is no longer one number. */
  const subtotals = useMemo(() => subtotalsByCurrency(cart), [cart])

  /**
   * The discount, per currency.
   *
   * A PERCENTAGE applies cleanly to each currency: ten per cent off is ten
   * per cent off whatever the line is priced in. An AMOUNT cannot — "5 000
   * off" of a basket holding dollars and so'm names a sum in one of them
   * and says nothing about the other — so it is applied to the tab's own
   * currency and the panel says as much when the basket is mixed.
   */
  const discounts = useMemo(() => {
    const out = new Map<number, number>()
    if (!active) return out

    const raw = Math.max(0, Number(active.discountValue) || 0)
    if (raw === 0) return out

    for (const [currencyId, sub] of subtotals) {
      const value =
        active.discountMode === 'percent'
          ? (sub * raw) / 100
          : currencyId === active.currencyId
            ? raw
            : 0
      // Capped, so a mistyped discount cannot produce a negative sale.
      if (value > 0) out.set(currencyId, round2(Math.min(value, sub)))
    }

    return out
  }, [active, subtotals])

  /** What is owed, per currency. */
  const totals = useMemo(() => {
    const out = new Map<number, number>()
    for (const [currencyId, sub] of subtotals) {
      out.set(currencyId, round2(sub - (discounts.get(currencyId) ?? 0)))
    }
    return out
  }, [subtotals, discounts])

  // The one remaining single-number figure, and it is only ever shown
  // beside its own currency. There is deliberately no `total` for the
  // basket as a whole: every place that wanted one now reads `totals`.
  const discount = [...discounts.values()].reduce((a, b) => a + b, 0)

  // Lines the cashier has not put a price on. Reachable two ways: switching to
  // a unit the shop never priced, or a product saved without a price at all.
  // Left alone it rings up as a giveaway and the shortfall only surfaces when
  // somebody counts the till at closing.
  const unpriced = cart.filter((l) => !(l.price > 0))
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


  /**
   * Switch a line to another unit.
   *
   * The price follows the unit, and when the shop never set one for this
   * unit the line goes to zero rather than keeping the bottle's price on a
   * box. Carrying it over is the exact loss the picker exists to prevent and
   * it would look entirely normal on screen; zero does not — the line turns
   * red and checkout refuses it.
   */
  function setLineUnit(productId: number, unitId: number | null) {
    mutateLines((lines) =>
      lines.map((l) =>
        l.productId === productId
          ? {
              ...l,
              productUnitId: unitId,
              price: priceFor(productsById.get(productId)!, unitId) ?? 0,
            }
          : l,
      ),
    )
  }

  /**
   * Move a line to the next currency the shop keeps.
   *
   * The PRICE IS NOT CONVERTED. There is no rate on this till and inventing
   * one would quietly restate what the customer is being charged; the number
   * stays as typed and only the currency it is counted in changes, which is
   * what a cashier correcting "that one is in dollars" actually means.
   */
  function cycleLineCurrency(productId: number, from: number) {
    const ids = currencies.map((c) => c.id)
    if (ids.length < 2) return

    const next = ids[(Math.max(0, ids.indexOf(from)) + 1) % ids.length]
    mutateLines((lines) =>
      lines.map((l) => (l.productId === productId ? { ...l, currencyId: next } : l)),
    )
  }

  /**
   * The one door to the payment step, for the button and for F4 alike.
   *
   * A hoisted declaration on purpose: the key handler is installed above the
   * consts it reads, and this keeps the two paths from drifting. They already
   * had — F4 walked straight past the unpriced-line check, on the keyboard
   * route this till was built for.
   */
  function openCheckout() {
    if (cart.length === 0) return

    if (unpriced.length > 0) {
      say('err', `Narxi yo'q: ${unpriced.map((l) => l.product.name).join(', ')}`)
      setPane('cart')
      return
    }

    setCheckout(true)
  }

  async function confirmSale(payment: Omit<Payment, 'discounts' | 'clientId'>) {
    if (!active) return

    // Snapshot the cart BEFORE it is cleared — the receipt needs the product
    // names, and they live nowhere else once the draft closes.
    const printed = [...cart]

    const outcome = await submitSale(
      cart,
      {
        ...payment,
        discounts: Object.fromEntries(discounts),
        clientId: active.clientId,
      },
      currencyId,
    )

    const unitNames = Object.fromEntries(
      printed.flatMap((l) =>
        (l.product.units ?? []).map((u) => [u.id, unitName(u.unit_id)] as const),
      ),
    )

    // One slip per currency, each attached to its own outbox row so Navbat
    // can reprint either half on its own.
    const slips = outcome.parts.map((part) => {
      const partLines = printed.filter((l) => l.currencyId === part.currencyId)

      const slip = receiptFromSale(part, partLines, {
        shopName: me.shop.name,
        sellerName: me.user.name ?? me.user.phone_number ?? '—',
        clientName: active.clientName,
        currency: currencyCode(part.currencyId),
        paymentType: payment.paymentType,
        discount: part.discount,
        isCredit: part.paid + 0.000001 < part.total,
        // product_unit_id -> "karobka". Resolved here because this is the
        // only place holding both the cart and the units table.
        unitNames,
      })

      return { part, slip }
    })

    for (const { part, slip } of slips) await attachReceipt(part.seq, slip)
    const slip = slips[0].slip

    // The finished tab is closed rather than emptied: a seller who rang up a
    // sale is done with that customer, and an empty tab left behind would
    // accumulate one per sale over a day.
    undoStacks.current.delete(active.id)
    const next = await closeDraft(active.id)
    setActiveId(next ?? (await createDraft(shopCurrency)).id)

    setCheckout(false)
    setPending(await pendingCount())
    setReceipt(slip)
    setMoreReceipts(slips.slice(1).map((s) => s.slip))

    // Per currency, joined — never added up.
    const money = outcome.parts
      .map((p) => `${formatMoney(p.total)} ${currencyCode(p.currencyId)}`)
      .join(' + ')

    if (outcome.synced) {
      const back = outcome.parts
        .filter((p) => p.change > 0)
        .map((p) => `${formatMoney(p.change)} ${currencyCode(p.currencyId)}`)
        .join(' + ')

      say('ok', `Sotuv yozildi: ${money}${back ? ` · Qaytim: ${back}` : ''}`)
    } else {
      // Not an error. The sale is durable in the outbox; it just has not
      // reached the server yet.
      say('warn', `Sotuv navbatga qo'yildi (${money}). Internet paydo bo'lganda yuboriladi.`)
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

      {view === 'history' && <History me={me} />}
      {view === 'clients' && <Clients shopCurrencyId={me.shop.currency_id} />}
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

            {/* Phone only. Two panes side by side do not fit 375pt, but the
                list was simply hidden there — leaving a phone with no way to
                browse at all, only to type a name it had to know already.
                One at a time, switched here, and each gets the full height.

                Driven by the same `pane` state the keyboard uses, so the
                visible pane and the focused pane can never disagree. */}
            <div className="pane-switch" role="tablist">
              <button
                role="tab"
                aria-selected={pane === 'browser'}
                className={pane === 'browser' ? 'on' : ''}
                onClick={() => setPane('browser')}
              >
                Mahsulotlar
              </button>
              <button
                role="tab"
                aria-selected={pane === 'cart'}
                className={pane === 'cart' ? 'on' : ''}
                onClick={() => setPane('cart')}
              >
                Savat{cart.length > 0 ? ` · ${cart.length}` : ''}
              </button>
            </div>

            <div className={`split ${splitDir} showing-${pane}`}>
              <div className="split-pane">
                <ProductBrowser
                  ref={browserRef}
                  products={products}
                  focused={pane === 'browser'}
                  onPick={(product) => {
                    mutateLines((lines) => addLine(lines, product))
                    say('ok', `${product.name} qo'shildi`)
                  }}
                  onLeave={() => setPane('cart')}
                />
              </div>

              <div
                className={`split-pane cart-pane ${pane === 'cart' ? 'focused' : ''}`}
                tabIndex={-1}
                ref={cartPaneRef}
              >
            <div className="cart-head-row">
              <SortHeader label="MAHSULOT" column="name" state={cartSort} onChange={setCartSort} />
              <SortHeader
                label="MIQDORI"
                column="qty"
                state={cartSort}
                onChange={setCartSort}
                align="center"
              />
              <SortHeader
                label="NARXI"
                column="price"
                state={cartSort}
                onChange={setCartSort}
                align="center"
              />
              <SortHeader
                label="JAMI"
                column="total"
                state={cartSort}
                onChange={setCartSort}
                align="right"
                title="Uchinchi bosishda skanerlash tartibiga qaytadi"
              />
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

              {cart.map((line, index) => {
                const stock = line.product.quantity
                // Stock is kept in base units, so the comparison has to be
                // made there too. Against the raw line quantity, one karobka
                // against six dona reads as 1 > 6 and says nothing while it
                // takes the shelf to −6.
                const lineUnit = line.product.units?.find((u) => u.id === line.productUnitId)
                const lineBase = lineUnit
                  ? (line.quantity * lineUnit.numerator) / lineUnit.denominator
                  : line.quantity
                const oversell = stock !== null && lineBase > stock
                const units = (line.product.units ?? []).filter((u) => u.is_active)

                return (
                  <div
                    className={`cart-row ${pane === 'cart' && index === cartCursor ? 'on' : ''} ${
                      line.price > 0 ? '' : 'unpriced'
                    }`}
                    key={line.product.id}
                    onClick={() => {
                      setPane('cart')
                      setCartCursor(index)
                    }}
                  >
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
                          Qoldiqdan ko'p — {formatMoney(lineBase)} kerak, {formatMoney(stock!)}{' '}
                          {unitName(line.product.unit_id)} bor. Minusga tushadi.
                        </div>
                      )}
                    </div>

                    <div className="cell c">
                      <div className="stepper">
                        {/* Touch only. On a desktop till + and − are keys;
                            on a phone there are none, so going from 1 to 3
                            meant selecting the field and retyping it — the
                            most common edit on the screen, made the fiddliest.
                            Floors at 1 like the keyboard does: removing a line
                            is the ✕, and a row that vanished under a repeated
                            tap is a sale quietly short an item. */}
                        <button
                          type="button"
                          className="step-btn"
                          aria-label="Kamaytirish"
                          onClick={(e) => {
                            e.stopPropagation()
                            mutateLines((lines) =>
                              lines.map((l) =>
                                l.productId === line.product.id
                                  ? { ...l, quantity: Math.max(1, round2(l.quantity - 1)) }
                                  : l,
                              ),
                            )
                          }}
                        >
                          −
                        </button>
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
                        {/* A product sold only one way shows its unit as a
                            label; one sold by the box AND the bottle gets a
                            picker, because which one is being sold changes
                            both the price and how much stock leaves. */}
                        <button
                          type="button"
                          className="step-btn"
                          aria-label="Ko'paytirish"
                          onClick={(e) => {
                            e.stopPropagation()
                            mutateLines((lines) =>
                              lines.map((l) =>
                                l.productId === line.product.id
                                  ? { ...l, quantity: round2(l.quantity + 1) }
                                  : l,
                              ),
                            )
                          }}
                        >
                          +
                        </button>
                      </div>

                      {/*
                        The unit, on its own line under the stepper.
                        
                        It used to be a <select> wedged into the stepper beside
                        the number, where the name was clipped to a few
                        characters, changing it took a drop-down, and nothing
                        said what a karobka was worth. Which unit is being sold
                        decides both the price and how much stock leaves the
                        shelf, so it is worth a row of its own: each way of
                        selling the product is a button, and the conversion is
                        written on it.
                      */}
                      {units.length > 1 ? (
                        <select
                          className="unit-select"
                          value={line.productUnitId ?? ''}
                          aria-label="Birlik"
                          onClick={(e) => e.stopPropagation()}
                          onChange={(e) => {
                            setCartCursor(index)
                            setLineUnit(
                              line.product.id,
                              e.target.value === '' ? null : Number(e.target.value),
                            )
                          }}
                        >
                          {units.map((u) => (
                            <option key={u.id} value={u.id}>
                              {unitName(u.unit_id)}
                              {conversionLabel(u) ? ` ${conversionLabel(u)}` : ''}
                            </option>
                          ))}
                        </select>
                      ) : (
                        <div className="unit-one">{unitName(line.product.unit_id)}</div>
                      )}
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
                        {/*
                          The currency of THIS line.
                          
                          One button cycling the shop's currencies rather than
                          a drop-down: there are two of them in practice, and
                          a basket holding a dollar-priced phone beside a
                          so'm-priced loaf is an ordinary visit that used to
                          have to be rung up as two separate sales.
                        */}
                        {currencies.length > 1 ? (
                          <button
                            type="button"
                            className="line-ccy"
                            title="Valyutani almashtirish"
                            aria-label={`Valyuta: ${currencyCode(line.currencyId)}`}
                            onClick={(e) => {
                              e.stopPropagation()
                              setCartCursor(index)
                              cycleLineCurrency(line.product.id, line.currencyId)
                            }}
                          >
                            {currencyCode(line.currencyId)}
                          </button>
                        ) : (
                          <span className="unit">{currencyCode(line.currencyId)}</span>
                        )}
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
            </div>

            {/* Written down because a keyboard-only flow that nobody is told
                about is a keyboard-only flow nobody uses. */}
            <div className="keyhelp">
              <span><kbd>F3</kbd>qidirish</span>
              <span><kbd>↑↓</kbd>tanlash</span>
              <span><kbd>Enter</kbd>qo'shish</span>
              <span><kbd>Tab</kbd>panel</span>
              <span><kbd>+</kbd><kbd>−</kbd>miqdor</span>
              <span><kbd>Del</kbd>o'chirish</span>
              <span><kbd>F4</kbd>to'lov</span>
              <span><kbd>F6</kbd>birlik</span>
              <span><kbd>F7</kbd>mijoz</span>
              <span><kbd>F8</kbd>{splitDir === 'vertical' ? 'yuqori/past' : 'yonma-yon'}</span>
              <span><kbd>F2</kbd>menyu</span>
              <span><kbd>F9</kbd>sinxron</span>
            </div>
          </div>

          {/* ─── Sale-wide decisions ─── */}
          <div className={`right ${sideOpen ? 'open' : ''}`}>
            {/* Phone only. Shows what has been decided so the panel does not
                have to be opened to check, and opens it when it does. */}
            <button
              type="button"
              className="side-peek"
              onClick={() => setSideOpen((v) => !v)}
              aria-expanded={sideOpen}
            >
              <span className={`chip ${client ? 'on' : ''}`}>
                {client ? client.name : 'Mijoz'}
              </span>
              <span className={`chip ${discount > 0 ? 'on' : ''}`}>
                {discount > 0 ? `− ${formatMoney(discount)}` : 'Chegirma'}
              </span>
              <span className="chip">{currencyCode(currencyId)}</span>
              <span className="side-peek-caret" aria-hidden>
                {sideOpen ? '▾' : '▴'}
              </span>
            </button>

            <div className="side-detail">
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
            </div>

            <div className="totals">
              <div className="row">
                <span className="muted">{cart.length} ta qator</span>
                <span className="muted">
                  {formatMoney(cart.reduce((n, l) => n + l.quantity, 0))} dona
                </span>
              </div>
              {/* One block per currency, never a sum of them. The line a
                  cashier reads out to the customer is the one place a
                  made-up number does the most damage. */}
              {[...subtotals].map(([lineCurrency, sub]) => {
                const off = discounts.get(lineCurrency) ?? 0
                const code = currencyCode(lineCurrency)

                return (
                  <div key={lineCurrency}>
                    <div className="row">
                      <span className="muted">Jami{subtotals.size > 1 ? ` · ${code}` : ''}</span>
                      <span>{formatMoney(sub)}</span>
                    </div>
                    {off > 0 && (
                      <div className="row" style={{ color: 'var(--warn-text)' }}>
                        <span>Chegirma</span>
                        <span>− {formatMoney(off)}</span>
                      </div>
                    )}
                    <div className="row grand">
                      <span>To'lash</span>
                      <span>
                        {formatMoney(totals.get(lineCurrency) ?? 0)}{' '}
                        {subtotals.size > 1 ? code : ''}
                      </span>
                    </div>
                  </div>
                )
              })}
              {subtotals.size > 1 && active?.discountMode === 'amount' && (
                <div className="hint">
                  Chegirma faqat {currencyCode(active.currencyId)} qatorlariga
                  qo'llanadi. Foiz tanlansa — hammasiga.
                </div>
              )}
            </div>

            <div className="actions">
              <button
                className="ghost"
                onClick={() => active && void closeTab(active.id)}
                disabled={!active}
              >
                Bekor qilish
              </button>
              <button
                className="primary"
                disabled={cart.length === 0 || unpriced.length > 0}
                onClick={openCheckout}
              >
                {/* Per currency, joined with +, never added. This button
                    read "12 006 UZS" for a basket of 12 000 so'm and 6
                    dollars — a number that is not money, on the control the
                    cashier presses to take it. */}
                To'lov qilish:{' '}
                {[...totals]
                  .map(([id, value]) => `${formatMoney(value)} ${currencyCode(id)}`)
                  .join(' + ')}
              </button>
            </div>

            {/* Named, not just blocked: "To'lov qilish" going grey with no
                reason is worse than the giveaway it prevents. */}
            {unpriced.length > 0 && (
              <div className="notice err">
                Narxi yo'q: {unpriced.map((l) => l.product.name).join(', ')}. Narxni qatorga
                yozing yoki mahsulotni savatdan chiqaring.
              </div>
            )}

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
          totals={[...totals].map(([currencyId, value]) => ({
            currencyId,
            code: currencyCode(currencyId),
            total: value,
          }))}
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

      {receipt && (
        <ReceiptView
          receipt={receipt}
          more={moreReceipts}
          onClose={() => {
            setReceipt(null)
            setMoreReceipts([])
          }}
        />
      )}
    </div>
  )
}
