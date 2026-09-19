import { useCallback, useEffect, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { fetchRecentSales, type MeResponse, type RecentSale } from '../api'
import { db, type Currency } from '../db'
import { formatMoney } from '../sales'
import { receiptFromHistory, type Receipt } from '../receipt'
import { ReceiptView } from './Receipt'

/**
 * What the shop sold, newest first.
 *
 * Server-backed rather than read from the outbox: the outbox only knows what
 * THIS device sent, and the question a seller asks is about the shop — what did
 * the other till ring up, did the morning's sales land. Offline it says so
 * plainly instead of showing a partial list that reads as complete.
 */
export function History({ me }: { me: MeResponse }) {
  const [sales, setSales] = useState<RecentSale[]>([])
  const [mineOnly, setMineOnly] = useState(false)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [expanded, setExpanded] = useState<number | null>(null)
  const [receipt, setReceipt] = useState<Receipt | null>(null)

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
        </div>
      )}

      <div className="view-body">
        {!busy && !error && sales.length === 0 && (
          <div className="cart-empty">Hozircha sotuv yo'q</div>
        )}

        {sales.map((sale) => (
          <div className={`hist-row ${sale.is_cancelled ? 'cancelled' : ''}`} key={sale.id}>
            <button
              className="hist-main"
              onClick={() => setExpanded(expanded === sale.id ? null : sale.id)}
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

      {receipt && <ReceiptView receipt={receipt} onClose={() => setReceipt(null)} />}
    </div>
  )
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
