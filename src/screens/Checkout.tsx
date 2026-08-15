import { useState } from 'react'
import { formatMoney, round2, type Payment } from '../sales'

const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Naqd',
  card: 'Karta',
  terminal: 'Terminal',
  bank_account: 'Hisob raqam',
}

const QUICK_NOTES = [1000, 5000, 10000, 50000, 100000]

/**
 * Payment only.
 *
 * Discount and customer live in the sidebar, where they belong: both are
 * decided while the basket is being built, not at the moment money changes
 * hands. What is left here is the one thing that happens at the counter — how
 * much was handed over, in what form.
 *
 * The amount tendered is entered as given and the change is derived, never the
 * other way round, so the receipt records what actually happened rather than
 * what was owed.
 */
export function Checkout({
  total,
  clientId,
  onCancel,
  onConfirm,
}: {
  total: number
  clientId: number | null
  onCancel: () => void
  onConfirm: (payment: Omit<Payment, 'discount' | 'clientId'>) => Promise<void>
}) {
  const [paymentType, setPaymentType] = useState<Payment['paymentType']>('cash')
  const [paid, setPaid] = useState(String(total))
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const paidValue = paymentType === null ? 0 : round2(Math.max(0, Number(paid) || 0))
  const change = paidValue > total ? round2(paidValue - total) : 0
  const owed = paidValue < total ? round2(total - paidValue) : 0
  const isCredit = owed > 0

  async function submit() {
    setError(null)

    if (isCredit && clientId === null) {
      setError("To'liq to'lanmagan sotuv nasiya hisoblanadi — o'ng tomondan mijoz tanlang.")
      return
    }

    setBusy(true)
    try {
      await onConfirm({ paymentType, paidAmount: paidValue, note })
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Xatolik')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="overlay" onClick={onCancel}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>To'lov — {formatMoney(total)}</h2>

        {error && <div className="notice err">{error}</div>}

        <div className="field">
          <label>To'lov turi</label>
          <div className="seg">
            {Object.entries(PAYMENT_LABELS).map(([value, label]) => (
              <button
                key={value}
                type="button"
                className={paymentType === value ? 'on' : ''}
                onClick={() => {
                  setPaymentType(value as Payment['paymentType'])
                  setPaid(String(total))
                }}
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

        {paymentType !== null && (
          <div className="field">
            <label htmlFor="paid">Berilgan summa</label>
            <input
              id="paid"
              type="number"
              min="0"
              autoFocus
              value={paid}
              onChange={(e) => setPaid(e.target.value)}
            />
            <div className="seg" style={{ marginTop: 8 }}>
              <button type="button" onClick={() => setPaid(String(total))}>
                Aniq
              </button>
              {QUICK_NOTES.filter((n) => n > total).slice(0, 3).map((n) => (
                <button key={n} type="button" onClick={() => setPaid(String(n))}>
                  {formatMoney(n)}
                </button>
              ))}
            </div>
          </div>
        )}

        <div className="field">
          <label htmlFor="note">Izoh</label>
          <input id="note" value={note} onChange={(e) => setNote(e.target.value)} />
        </div>

        <div className="totals" style={{ borderTop: '1px solid var(--line)', padding: '12px 0 0' }}>
          <div className="row grand">
            <span>To'lash</span>
            <span>{formatMoney(total)}</span>
          </div>
          {change > 0 && (
            <div className="row" style={{ color: '#8ee0b6', fontSize: 17, fontWeight: 600 }}>
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
            Orqaga
          </button>
          <button type="button" className="primary" style={{ flex: 2 }} onClick={submit} disabled={busy}>
            {busy ? 'Yozilmoqda…' : 'Yakunlash'}
          </button>
        </div>
      </div>
    </div>
  )
}
