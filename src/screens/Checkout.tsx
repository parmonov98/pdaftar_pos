import { useMemo, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { db } from '../db'
import { cartSubtotal, formatMoney, round2, type CartLine, type Payment } from '../sales'

const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Naqd',
  card: 'Karta',
  terminal: 'Terminal',
  bank_account: 'Hisob raqam',
}

/**
 * The payment sheet.
 *
 * Two rules are enforced here rather than left to the server, because both are
 * things the cashier can still fix while the customer is present:
 *
 *   - nasiya (anything unpaid) must name a real client;
 *   - the amount handed over is entered as given, and change is derived —
 *     never the other way round, so the receipt records what actually
 *     happened rather than what was owed.
 */
export function Checkout({
  lines,
  onCancel,
  onConfirm,
}: {
  lines: CartLine[]
  onCancel: () => void
  onConfirm: (payment: Payment) => Promise<void>
}) {
  const subtotal = cartSubtotal(lines)

  const [discount, setDiscount] = useState('0')
  const [paymentType, setPaymentType] = useState<Payment['paymentType']>('cash')
  const [clientId, setClientId] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const discountValue = round2(Math.max(0, Math.min(Number(discount) || 0, subtotal)))
  const total = round2(subtotal - discountValue)

  const [paid, setPaid] = useState<string>(String(total))
  const paidValue = round2(Math.max(0, Number(paid) || 0))

  const change = paidValue > total ? round2(paidValue - total) : 0
  const owed = paidValue < total ? round2(total - paidValue) : 0
  const isCredit = owed > 0

  const clients = useLiveQuery(() => db.clients.orderBy('name').limit(300).toArray(), [], [])

  const walkIn = useMemo(
    () => clients.find((c) => c.name === 'Naqd xaridor' && c.phone_number === null),
    [clients],
  )

  async function submit() {
    setError(null)

    if (isCredit && clientId === null) {
      setError('Nasiya (to\'liq to\'lanmagan) sotuv uchun mijoz tanlang.')
      return
    }

    setBusy(true)
    try {
      await onConfirm({
        paymentType,
        paidAmount: paidValue,
        discount: discountValue,
        clientId,
        note,
      })
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Xatolik')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="overlay" onClick={onCancel}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>To'lov</h2>

        {error && <div className="notice err">{error}</div>}

        <div className="field">
          <label>To'lov turi</label>
          <div className="seg">
            {Object.entries(PAYMENT_LABELS).map(([value, label]) => (
              <button
                key={value}
                type="button"
                className={paymentType === value ? 'on' : ''}
                onClick={() => setPaymentType(value as Payment['paymentType'])}
              >
                {label}
              </button>
            ))}
            <button
              type="button"
              className={paymentType === null ? 'on' : ''}
              onClick={() => {
                setPaymentType(null)
                setPaid('0')
              }}
            >
              Nasiya
            </button>
          </div>
        </div>

        <div className="row2">
          <div className="field">
            <label htmlFor="disc">Chegirma</label>
            <input
              id="disc"
              type="number"
              min="0"
              value={discount}
              onChange={(e) => {
                setDiscount(e.target.value)
                const next = round2(subtotal - Math.max(0, Number(e.target.value) || 0))
                // Keep the tendered amount in step with the total while the
                // cashier is still adjusting it — retyping the full sum after
                // every discount keystroke is how wrong figures get entered.
                if (paymentType !== null) setPaid(String(Math.max(0, next)))
              }}
            />
          </div>
          <div className="field">
            <label htmlFor="paid">Berilgan summa</label>
            <input
              id="paid"
              type="number"
              min="0"
              value={paid}
              onChange={(e) => setPaid(e.target.value)}
              disabled={paymentType === null}
            />
          </div>
        </div>

        <div className="field">
          <label htmlFor="client">
            Mijoz {isCredit && <span style={{ color: '#f0cf8a' }}>— nasiya uchun majburiy</span>}
          </label>
          <select
            id="client"
            value={clientId ?? ''}
            onChange={(e) => setClientId(e.target.value === '' ? null : Number(e.target.value))}
          >
            <option value="">
              {isCredit ? '— tanlang —' : "Naqd xaridor (ismsiz)"}
            </option>
            {clients
              .filter((c) => c.id !== walkIn?.id)
              .map((client) => (
                <option key={client.id} value={client.id}>
                  {client.name}
                  {client.phone_number ? ` · ${client.phone_number}` : ''}
                </option>
              ))}
          </select>
        </div>

        <div className="field">
          <label htmlFor="note">Izoh</label>
          <input id="note" value={note} onChange={(e) => setNote(e.target.value)} />
        </div>

        <div className="totals" style={{ borderTop: '1px solid var(--line)', padding: '12px 0 0' }}>
          <div className="row">
            <span className="muted">Jami</span>
            <span>{formatMoney(subtotal)}</span>
          </div>
          {discountValue > 0 && (
            <div className="row">
              <span className="muted">Chegirma</span>
              <span>− {formatMoney(discountValue)}</span>
            </div>
          )}
          <div className="row grand">
            <span>To'lash</span>
            <span>{formatMoney(total)}</span>
          </div>
          {change > 0 && (
            <div className="row" style={{ color: '#8ee0b6' }}>
              <span>Qaytim</span>
              <span>{formatMoney(change)}</span>
            </div>
          )}
          {owed > 0 && (
            <div className="row" style={{ color: '#f0cf8a' }}>
              <span>Qarzga qoladi</span>
              <span>{formatMoney(owed)}</span>
            </div>
          )}
        </div>

        <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
          <button type="button" className="ghost" style={{ flex: 1 }} onClick={onCancel}>
            Bekor
          </button>
          <button type="button" className="primary" style={{ flex: 2 }} onClick={submit} disabled={busy}>
            {busy ? 'Yozilmoqda…' : 'Sotuvni yakunlash'}
          </button>
        </div>
      </div>
    </div>
  )
}
