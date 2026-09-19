import { useEffect, useRef, useState } from 'react'
import { formatMoney, round2, type Payment } from '../sales'

const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Naqd',
  card: 'Karta',
  terminal: 'Terminal',
  bank_account: 'Hisob raqam',
}

const QUICK_NOTES = [1000, 5000, 10000, 50000, 100000]

/**
 * What the customer plausibly handed over.
 *
 * Notes above the total first, then round numbers above it — a 340 000 sale
 * had no suggestions at all before, because the largest note in circulation
 * is 100 000 and the list stopped there. Those are the baskets where the
 * mental arithmetic is hardest and the buttons were missing.
 */
function tenderSuggestions(total: number): number[] {
  const notes = QUICK_NOTES.filter((n) => n > total)

  const rounded = [10_000, 50_000, 100_000]
    .map((step) => Math.ceil((total + 1) / step) * step)
    .filter((n) => n > total)

  return [...new Set([...notes, ...rounded])].sort((a, b) => a - b).slice(0, 3)
}

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

  const finishRef = useRef<HTMLButtonElement>(null)

  // Nasiya hides the amount field, so nothing would hold focus and Enter
  // would land on the document and do nothing — with the dialog still
  // promising Enter finishes. Put focus on the button it names.
  useEffect(() => {
    if (paymentType === null) finishRef.current?.focus()
  }, [paymentType])

  const paidValue = paymentType === null ? 0 : round2(Math.max(0, Number(paid) || 0))
  const change = paidValue > total ? round2(paidValue - total) : 0
  const owed = paidValue < total ? round2(total - paidValue) : 0
  const isCredit = owed > 0

  async function submit(event?: React.FormEvent) {
    event?.preventDefault()
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
    <div className="overlay" onClick={() => !busy && onCancel()}>
      {/* Enter finishes, Escape goes back. The till is sold on being usable
          without a mouse and this dialog was the point where that stopped
          being true: F4 opened it and then no key did anything at all.

          A real form, so Enter from the amount field submits the way it does
          in every other input on the machine, and the handler in Pos already
          steps aside while this is open. */}
      <form
        className="modal"
        onClick={(e) => e.stopPropagation()}
        onSubmit={submit}
        onKeyDown={(e) => {
          if (e.key === 'Escape' && !busy) {
            e.stopPropagation()
            onCancel()
          }
        }}
      >
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
              {tenderSuggestions(total).map((n) => (
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
            <div className="row" style={{ color: 'var(--ok-text)', fontSize: 17, fontWeight: 600 }}>
              <span>Qaytim</span>
              <span>{formatMoney(change)}</span>
            </div>
          )}
          {owed > 0 && (
            <div className="row" style={{ color: 'var(--warn-text)' }}>
              <span>Qarzga qoladi</span>
              <span>{formatMoney(owed)}</span>
            </div>
          )}
        </div>

        <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
          <button type="button" className="ghost" style={{ flex: 1 }} onClick={onCancel}>
            Orqaga
          </button>
          <button ref={finishRef} type="submit" className="primary" style={{ flex: 2 }} disabled={busy}>
            {busy ? 'Yozilmoqda…' : 'Yakunlash'}
          </button>
        </div>

        <div className="keyhelp" style={{ marginTop: 10 }}>
          <b>Enter</b> yakunlash · <b>Esc</b> orqaga
        </div>
      </form>
    </div>
  )
}
