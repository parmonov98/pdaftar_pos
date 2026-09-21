import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { fetchRecentSales, type MeResponse, type RecentSale } from '../api'
import { db, type Currency } from '../db'
import { cancelBlocker, cancelEffects, cancelSale, formatMoney } from '../sales'
import { sortRows, type SortState } from '../sorting'
import { pullAll } from '../sync'
import { toast } from '../toast'
import { SortHeader } from './SortHeader'
import { receiptFromHistory, type Receipt } from '../receipt'
import { ReceiptView } from './Receipt'

/**
 * What the shop sold, newest first.
 *
 * Server-backed rather than read from the outbox: the outbox only knows what
 * THIS device sent, and the question a seller asks is about the shop — what did
 * the other till ring up, did the morning's sales land. Offline it says so
 * plainly instead of showing a partial list that reads as complete.
 *
 * It is also where a sale is undone. A cashier who rings up the wrong item, or
 * a customer who brings something back, had no remedy at the till at all: the
 * operation, the service and the ledger reversal all existed and nothing on
 * screen reached them.
 */
/** The columns Tarix can be ordered by. */
type SaleCol = 'when' | 'seller' | 'client' | 'total'

export function History({ me }: { me: MeResponse }) {
  const [sales, setSales] = useState<RecentSale[]>([])
  /** null = newest first, which is what the server already sent. */
  const [sort, setSort] = useState<SortState<SaleCol>>(null)
  const [mineOnly, setMineOnly] = useState(false)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [expanded, setExpanded] = useState<number | null>(null)
  const [receipt, setReceipt] = useState<Receipt | null>(null)
  const [cancelling, setCancelling] = useState<RecentSale | null>(null)

  /** Which row the keyboard is on. Index, not id — the list is re-fetched. */
  const [cursor, setCursor] = useState(0)
  const rowsRef = useRef<HTMLDivElement | null>(null)

  const online = useOnline()

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      setSales(await fetchRecentSales({ limit: 100, mine: mineOnly }))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Tarixni yuklab bo\'lmadi')
    } finally {
      setBusy(false)
    }
  }, [mineOnly])

  useEffect(() => {
    void load()
  }, [load])

  const currencies = useLiveQuery(() => db.currencies.toArray(), [], [] as Currency[])

  const code = (id: number | null) =>
    id === null ? '' : (currencies.find((c) => c.id === id)?.code ?? '')

  /**
   * Today's takings, per currency.
   *
   * Summed across currencies this was nonsense arithmetic — 12,000 som and
   * 11 dollars reported as 12,011, with no unit written anywhere to give the
   * number away. A shop that sells in two currencies would have read its own
   * day wrong every time it looked.
   */
  const dayTotals = sales
    .filter((s) => !s.is_cancelled && isToday(s.created_at))
    .reduce<Map<number | null, number>>((acc, s) => {
      acc.set(s.currency_id, (acc.get(s.currency_id) ?? 0) + s.total)
      return acc
    }, new Map())

  const rows = useMemo(() => {
    const ordered = sortRows(sales, sort, (sale, key) => {
      if (key === 'when') return sale.created_at
      if (key === 'seller') return sale.seller_name
      // Cash sales have no customer. They collect at the end rather than
      // under one letter, because "Naqd xaridor" is not a name and sorting
      // a hundred of them into the N's buries the customers you are
      // looking for.
      if (key === 'client') return sale.client_name
      return sale.total
    })

    /*
     * Amounts are ordered WITHIN a currency, never across one.
     *
     * This shop's own Tarix has 589,000 UZS and 620,012 USD sitting in the
     * same list. Ranked by the bare number, an eleven-dollar sale files
     * below a twelve-thousand-so'm one — the same mistake as adding them up,
     * which the day's-takings line already refuses to make. A second stable
     * pass by currency leaves each currency's sales grouped and each group
     * ordered by size.
     */
    if (sort?.key !== 'total') return ordered

    return [...ordered].sort(
      (a, b) => (a.currency_id ?? 0) - (b.currency_id ?? 0),
    )
  }, [sales, sort])

  // A refreshed list must not leave the cursor pointing past the end.
  useEffect(() => {
    setCursor((c) => Math.max(0, Math.min(c, sales.length - 1)))
  }, [sales.length])

  /*
   * Tarix on the keyboard.
   *
   * The till is sold on being usable without a mouse — the sale screen has
   * been since it shipped — and this screen was where that stopped: every row
   * was reachable only by tabbing through three buttons per sale, which on a
   * hundred-row list is not reachable at all.
   *
   *   ↑ ↓     move between sales
   *   Enter   open/close the lines
   *   Delete  cancel the highlighted sale (asks first)
   *
   * Escape is deliberately NOT bound here. It means "back to the sale
   * screen" everywhere in the app, and two window listeners both acting on
   * one Escape would have collapsed the open row AND left the screen.
   *
   * Delete rather than a letter, and the same key the cart uses to drop a
   * line, so the destructive key is one key on this machine rather than one
   * per screen. It opens the confirmation; nothing is undone by a keypress.
   */
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      // A dialog owns its own keys — the confirmation has Escape, the receipt
      // has its own close.
      if (cancelling || receipt) return

      const target = event.target as HTMLElement | null
      if (target?.tagName === 'INPUT' || target?.tagName === 'TEXTAREA' || target?.tagName === 'SELECT') {
        return
      }

      if (rows.length === 0) return

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

      const sale = rows[cursor]
      if (!sale) return

      if (event.key === 'Enter') {
        // Not when a button has focus: Enter belongs to that button, and
        // stealing it would make Tab-then-Enter do something else.
        if (target?.tagName === 'BUTTON') return
        event.preventDefault()
        setExpanded((id) => (id === sale.id ? null : sale.id))
        return
      }

      if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault()
        if (!sale.is_cancelled) setCancelling(sale)
      }
    }

    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [rows, cursor, cancelling, receipt])

  // Keep the highlighted row on screen when the arrows walk off the edge.
  useEffect(() => {
    rowsRef.current
      ?.querySelector('[data-cursor="true"]')
      ?.scrollIntoView({ block: 'nearest' })
  }, [cursor, rows])

  async function confirmCancel(sale: RecentSale) {
    const outcome = await cancelSale(sale, code(sale.currency_id))

    if (outcome.synced) {
      // The stock went back and a debt may have moved, and both of those live
      // in the local cache the rest of the till sells from.
      await pullAll().catch(() => undefined)
      toast('ok', `#${sale.id} bekor qilindi.`)
    } else {
      // Not lost: it is in the outbox naming a sale id the server knows, and
      // the next Sinxronlash carries it up.
      toast(
        'warn',
        `#${sale.id} navbatga qo'yildi — yuborilmadi${outcome.error ? `: ${outcome.error}` : ''}. Sinxronlashda ketadi.`,
      )
    }

    setCancelling(null)
    await load()
  }

  return (
    <div className="view">
      <div className="view-head">
        <h2>Tarix</h2>
        <div className="seg" style={{ maxWidth: 280 }}>
          <button className={mineOnly ? '' : 'on'} onClick={() => setMineOnly(false)}>
            Do'kon bo'yicha
          </button>
          <button className={mineOnly ? 'on' : ''} onClick={() => setMineOnly(true)}>
            Faqat men
          </button>
        </div>
        <span className="spacer" />
        <button className="ghost" onClick={() => void load()} disabled={busy}>
          {busy ? 'Yuklanmoqda…' : 'Yangilash'}
        </button>
      </div>

      {error && (
        <div className="notice err">
          {error}
          <div className="hint">
            Tarix serverdan o'qiladi — internetsiz ko'rinmaydi. Sizning yuborilmagan
            sotuvlaringiz "Navbat" bo'limida turadi.
          </div>
        </div>
      )}

      {!error && sales.length > 0 && (
        <div className="view-summary">
          Bugungi savdo:{' '}
          {[...dayTotals.entries()].map(([currencyId, sum], index) => (
            <strong key={currencyId ?? 'none'}>
              {index > 0 && ' · '}
              {formatMoney(sum)} {code(currencyId)}
            </strong>
          ))}
          <span className="muted"> · oxirgi {sales.length} ta sotuv ko'rsatilgan</span>
          <span className="muted hint keyline">
            ↑ ↓ — tanlash · Enter — ochish · Delete — bekor qilish
          </span>
        </div>
      )}

      <div className="view-body" ref={rowsRef}>
        {!error && sales.length > 0 && (
          <div className="list-head hist-head">
            <SortHeader label="Mijoz" column="client" state={sort} onChange={setSort} />
            <SortHeader label="Sotuvchi" column="seller" state={sort} onChange={setSort} />
            <SortHeader label="Vaqti" column="when" state={sort} onChange={setSort} />
            <SortHeader
              label="Summa"
              column="total"
              state={sort}
              onChange={setSort}
              align="right"
              title="Har bir valyuta alohida guruhlanadi — so'm dollar bilan solishtirilmaydi"
            />
            <span className="hist-head-pad" />
          </div>
        )}
        {!busy && !error && sales.length === 0 && (
          <div className="cart-empty">Hozircha sotuv yo'q</div>
        )}

        {rows.map((sale, index) => (
          <div
            className={`hist-row ${sale.is_cancelled ? 'cancelled' : ''} ${index === cursor ? 'on' : ''}`}
            data-cursor={index === cursor}
            key={sale.id}
          >
            <button
              className="hist-main"
              onClick={() => {
                setCursor(index)
                setExpanded(expanded === sale.id ? null : sale.id)
              }}
            >
              <span className="grow">
                <span className="nm">
                  {/* Paid and nasiya are different events, not different states
                      of one — the badge says which before anything else. */}
                  {sale.kind === 'income' ? (
                    <span className="tag ok">naqd</span>
                  ) : (
                    <span className="tag warn">nasiya</span>
                  )}
                  {' '}
                  {sale.client_name ?? 'Naqd xaridor'}
                  {sale.is_cancelled && <span className="tag danger">bekor qilingan</span>}
                </span>
                <span className="sub">
                  {sale.seller_name ?? '—'}
                  {' · '}
                  {sale.created_at ? new Date(sale.created_at).toLocaleString('uz-UZ') : '—'}
                  {' · '}
                  {sale.items.length} qator
                  {sale.discount_amount > 0 && ` · chegirma ${formatMoney(sale.discount_amount)}`}
                </span>
              </span>
              {/* With the currency, always. Two rows reading "11" and
                  "12,000" are the same size on screen and are not remotely
                  the same amount of money. */}
              <span className="amt">
                {formatMoney(sale.total)} {code(sale.currency_id)}
              </span>
            </button>

            {/* Reprint. Works for another seller's sale too — the lines come
                from the server, so this device never saw them. */}
            <button
              className="ghost hist-print"
              onClick={() =>
                setReceipt(
                  receiptFromHistory(sale, {
                    shopName: me.shop.name,
                    currency: '',
                  }),
                )
              }
              title="Chekni qayta chiqarish"
            >
              🧾
            </button>

            {/* Undo. Kept off an already-cancelled row rather than disabled:
                a second press there does nothing, and a button that does
                nothing is a button a cashier presses twice to find out. */}
            {!sale.is_cancelled && (
              <button
                className="ghost hist-cancel"
                onClick={() => {
                  setCursor(index)
                  setCancelling(sale)
                }}
                title={online ? 'Sotuvni bekor qilish' : cancelBlocker(sale, online) ?? ''}
                aria-label={`#${sale.id} sotuvni bekor qilish`}
              >
                ✕
              </button>
            )}

            {expanded === sale.id && (
              <div className="hist-items">
                {sale.items.map((item, i) => (
                  <div className="hist-item" key={`${sale.id}-${i}`}>
                    <span className="grow">{item.name ?? `#${item.product_id}`}</span>
                    <span className="muted">
                      {item.quantity === null ? '—' : formatMoney(item.quantity)} ×
                    </span>
                    <span>{formatMoney(item.total)}</span>
                  </div>
                ))}
                {sale.is_credit && (
                  <div className="hist-item" style={{ color: 'var(--warn-text)' }}>
                    <span className="grow">To'langan</span>
                    <span>{formatMoney(sale.paid_amount)}</span>
                  </div>
                )}
              </div>
            )}
          </div>
        ))}
      </div>

      {cancelling && (
        <CancelSaleDialog
          sale={cancelling}
          currency={code(cancelling.currency_id)}
          online={online}
          onConfirm={() => confirmCancel(cancelling)}
          onClose={() => setCancelling(null)}
        />
      )}

      {receipt && <ReceiptView receipt={receipt} onClose={() => setReceipt(null)} />}
    </div>
  )
}

/**
 * "Are you sure" — with the sale named, and the consequences spelled out.
 *
 * A bare confirmation would be worse than none: the row under the cursor is
 * one of a hundred that look alike, and two of the consequences cost money out
 * of the drawer that nothing on the row hints at.
 */
function CancelSaleDialog({
  sale,
  currency,
  online,
  onConfirm,
  onClose,
}: {
  sale: RecentSale
  currency: string
  online: boolean
  onConfirm: () => Promise<void>
  onClose: () => void
}) {
  const [busy, setBusy] = useState(false)
  const blocker = cancelBlocker(sale, online)

  async function go() {
    if (blocker || busy) return
    setBusy(true)
    try {
      await onConfirm()
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="overlay" onClick={busy ? undefined : onClose}>
      <div
        className="modal"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="cancel-sale-title"
        onClick={(e) => e.stopPropagation()}
        onKeyDown={(e) => {
          if (e.key === 'Escape' && !busy) {
            e.stopPropagation()
            onClose()
          }
          // Enter is deliberately NOT bound to the destructive button here,
          // the way it is in the checkout. The safe button holds focus, so a
          // reflex Enter closes the dialog; cancelling a sale takes Tab first.
        }}
      >
        <h2 id="cancel-sale-title">Sotuvni bekor qilish</h2>

        <div className="totals" style={{ marginBottom: 12 }}>
          <div className="row">
            <span className="muted">
              #{sale.id} · {sale.client_name ?? 'Naqd xaridor'}
            </span>
            <span className="muted">
              {sale.created_at ? new Date(sale.created_at).toLocaleString('uz-UZ') : '—'}
            </span>
          </div>
          <div className="row">
            <span className="muted">
              {sale.seller_name ?? '—'} · {sale.items.length} qator
            </span>
            <span className="muted">{sale.kind === 'income' ? 'naqd' : 'nasiya'}</span>
          </div>
          <div className="row grand">
            <span>Summa</span>
            <span>
              {formatMoney(sale.total)} {currency}
            </span>
          </div>
        </div>

        {/* The first two lines of a receipt, so the cashier can see this is
            the sale they mean without closing the dialog to look. */}
        {sale.items.length > 0 && (
          <div className="hist-items" style={{ marginBottom: 12 }}>
            {sale.items.slice(0, 3).map((item, i) => (
              <div className="hist-item" key={i}>
                <span className="grow">{item.name ?? `#${item.product_id}`}</span>
                <span className="muted">
                  {item.quantity === null ? '—' : formatMoney(item.quantity)} ×
                </span>
                <span>{formatMoney(item.total)}</span>
              </div>
            ))}
            {sale.items.length > 3 && (
              <div className="hist-item muted">…yana {sale.items.length - 3} qator</div>
            )}
          </div>
        )}

        {blocker ? (
          <div className="notice err">{blocker}</div>
        ) : (
          <div className="notice warn">
            <ul style={{ margin: 0, paddingInlineStart: 18 }}>
              {cancelEffects(sale, currency).map((effect) => (
                <li key={effect}>{effect}</li>
              ))}
            </ul>
          </div>
        )}

        <div className="actions">
          {/* Focused first, on purpose: the dangerous button is one Tab away
              rather than under whatever key the cashier is already pressing. */}
          <button type="button" className="ghost" autoFocus onClick={onClose} disabled={busy}>
            Yo'q
          </button>
          <button
            type="button"
            className="primary danger"
            onClick={() => void go()}
            disabled={busy || blocker !== null}
          >
            {busy ? 'Bekor qilinmoqda…' : `Ha, bekor qilinsin`}
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * Whether the machine currently has a connection, as a reactive value.
 *
 * Read once at render it is a lie the moment the wifi drops: the cancel button
 * would stay enabled on a screen the cashier has been staring at for ten
 * minutes.
 */
function useOnline(): boolean {
  const [online, setOnline] = useState(() => navigator.onLine)

  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => {
      window.removeEventListener('online', on)
      window.removeEventListener('offline', off)
    }
  }, [])

  return online
}

function isToday(iso: string | null): boolean {
  if (!iso) return false
  const d = new Date(iso)
  const now = new Date()
  return (
    d.getDate() === now.getDate() &&
    d.getMonth() === now.getMonth() &&
    d.getFullYear() === now.getFullYear()
  )
}
