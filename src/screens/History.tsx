import { useCallback, useEffect, useState } from 'react'
import { fetchRecentSales, type RecentSale } from '../api'
import { formatMoney } from '../sales'

/**
 * What the shop sold, newest first.
 *
 * Server-backed rather than read from the outbox: the outbox only knows what
 * THIS device sent, and the question a seller asks is about the shop — what did
 * the other till ring up, did the morning's sales land. Offline it says so
 * plainly instead of showing a partial list that reads as complete.
 */
export function History() {
  const [sales, setSales] = useState<RecentSale[]>([])
  const [mineOnly, setMineOnly] = useState(false)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [expanded, setExpanded] = useState<number | null>(null)

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

  const dayTotal = sales
    .filter((s) => !s.is_cancelled && isToday(s.created_at))
    .reduce((sum, s) => sum + s.total, 0)

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
          Bugungi savdo: <strong>{formatMoney(dayTotal)}</strong>
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
                  #{sale.id} · {sale.client_name ?? '—'}
                  {sale.is_cancelled && <span className="tag danger">bekor qilingan</span>}
                  {!sale.is_cancelled && sale.is_credit && <span className="tag warn">nasiya</span>}
                </span>
                <span className="sub">
                  {sale.seller_name ?? '—'}
                  {' · '}
                  {sale.created_at ? new Date(sale.created_at).toLocaleString('uz-UZ') : '—'}
                  {' · '}
                  {sale.items.length} qator
                </span>
              </span>
              <span className="amt">{formatMoney(sale.total)}</span>
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
