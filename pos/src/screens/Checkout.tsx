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
function tenderSuggestions(total: number, code: string): number[] {
  /*
   * Both halves of this are so'm-shaped: the note list IS so'm, and the
   * 10 000 / 50 000 / 100 000 rounding steps are the sizes so'm comes in.
   * Applied to a six-dollar total they offered "10 000" and "50 000" — not
   * suggestions, just wrong numbers one tap away from the drawer.
   *
   * Another currency gets the round numbers of its own scale instead: the
   * next whole one, five and ten above the total, which is what a customer
   * actually hands over for a six-dollar item.
   */
  const steps = code === 'UZS' ? [10_000, 50_000, 100_000] : [1, 5, 10]
  const notes = code === 'UZS' ? QUICK_NOTES.filter((n) => n > total) : []

  const rounded = steps
    .map((step) => Math.ceil((total + step / 1000) / step) * step)
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
/** One currency's worth of what is owed. */
export type CheckoutTotal = { currencyId: number; code: string; total: number }

export function Checkout({
  totals,
  clientId,
  onCancel,
  onConfirm,
}: {
  /** One entry per currency in the basket — usually exactly one. */
  totals: CheckoutTotal[]
  clientId: number | null
  onCancel: () => void
  onConfirm: (payment: Omit<Payment, 'discounts' | 'clientId'>) => Promise<void>
}) {
  const [paymentType, setPaymentType] = useState<Payment['paymentType']>('cash')
  /** Tendered per currency, as typed. Keyed by currency id. */
  const [paid, setPaid] = useState<Record<number, string>>(() =>
    Object.fromEntries(totals.map((t) => [t.currencyId, String(t.total)])),
  )
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

  /**
   * What is owed and what was handed over, currency by currency.
   *
   * Never summed. A basket of dollars and so'm has two amounts owed and two
   * amounts tendered, and the one number that would combine them does not
   * exist — which is the whole reason a mixed sale is submitted as one sale
   * per currency.
   */
  const perCurrency = totals.map((t) => {
    const tendered = paymentType === null ? 0 : round2(Math.max(0, Number(paid[t.currencyId]) || 0))
    return {
      ...t,
      tendered,
      change: tendered > t.total ? round2(tendered - t.total) : 0,
      owed: tendered < t.total ? round2(t.total - tendered) : 0,
    }
  })

  const isCredit = perCurrency.some((c) => c.owed > 0)
  const mixed = totals.length > 1

  async function submit(event?: React.FormEvent) {
    event?.preventDefault()
    setError(null)

    if (isCredit && clientId === null) {
      setError("To'liq to'lanmagan sotuv nasiya hisoblanadi — o'ng tomondan mijoz tanlang.")
      return
    }

    setBusy(true)
    try {
      await onConfirm({
        paymentType,
        paid: Object.fromEntries(perCurrency.map((c) => [c.currencyId, c.tendered])),
        note,
      })
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
            return
          }

          // Explicit rather than relying on a form's implicit submission.
          // That default is browser- and input-dependent, and a till runs on
          // whatever hardware and kiosk browser the shop has; "Enter mostly
          // works" is not a thing to promise a cashier in writing. Buttons
          // are left alone — Enter already activates the focused one.
          if (e.key === 'Enter' && !busy && (e.target as HTMLElement).tagName !== 'BUTTON') {
            e.preventDefault()
            void submit()
          }
        }}
      >
        <h2>
          To'lov —{' '}
          {perCurrency.map((c, i) => (
            <span key={c.currencyId}>
              {i > 0 && ' + '}
              {formatMoney(c.total)} {c.code}
            </span>
          ))}
        </h2>

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
                  setPaid(Object.fromEntries(totals.map((t) => [t.currencyId, String(t.total)])))
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
                setPaid(Object.fromEntries(totals.map((t) => [t.currencyId, '0'])))
              }}
            >
              Nasiya
            </button>
          </div>
        </div>

        {/* One amount field per currency. A customer paying for a
            dollar-priced item and a so'm-priced one hands over two lots of
            money, and a single field could only ever record one of them. */}
        {paymentType !== null &&
          perCurrency.map((c, index) => (
            <div className="field" key={c.currencyId}>
              <label htmlFor={`paid-${c.currencyId}`}>
                Berilgan summa{mixed ? ` — ${c.code}` : ''}
              </label>
              <input
                id={`paid-${c.currencyId}`}
                type="number"
                min="0"
                autoFocus={index === 0}
                value={paid[c.currencyId] ?? ''}
                onChange={(e) =>
                  setPaid((prev) => ({ ...prev, [c.currencyId]: e.target.value }))
                }
              />
              <div className="seg" style={{ marginTop: 8 }}>
                <button
                  type="button"
                  onClick={() =>
                    setPaid((prev) => ({ ...prev, [c.currencyId]: String(c.total) }))
                  }
                >
                  Aniq
                </button>
                {tenderSuggestions(c.total, c.code).map((n) => (
                  <button
                    key={n}
                    type="button"
                    onClick={() => setPaid((prev) => ({ ...prev, [c.currencyId]: String(n) }))}
                  >
                    {formatMoney(n)}
                  </button>
                ))}
              </div>
            </div>
          ))}

        <div className="field">
          <label htmlFor="note">Izoh</label>
          <input id="note" value={note} onChange={(e) => setNote(e.target.value)} />
        </div>

        <div className="totals" style={{ borderTop: '1px solid var(--line)', padding: '12px 0 0' }}>
          {perCurrency.map((c) => (
            <div key={c.currencyId}>
              <div className="row grand">
                <span>To'lash{mixed ? ` · ${c.code}` : ''}</span>
                <span>
                  {formatMoney(c.total)} {c.code}
                </span>
              </div>
              {c.change > 0 && (
                <div
                  className="row"
                  style={{ color: 'var(--ok-text)', fontSize: 17, fontWeight: 600 }}
                >
                  <span>Qaytim</span>
                  <span>
                    {formatMoney(c.change)} {c.code}
                  </span>
                </div>
              )}
              {c.owed > 0 && (
                <div className="row" style={{ color: 'var(--warn-text)' }}>
                  <span>Qarzga qoladi</span>
                  <span>
                    {formatMoney(c.owed)} {c.code}
                  </span>
                </div>
              )}
            </div>
          ))}
          {mixed && (
            <div className="hint">
              Har bir valyuta alohida sotuv bo'lib yoziladi — qarz ham alohida
              hisoblanadi. Summalar qo'shilmaydi.
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
