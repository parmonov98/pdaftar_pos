import { useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { payClientDebt } from '../api'
import { db, type Client, type Currency } from '../db'
import { formatMoney, owedIn } from '../sales'
import { pullAll } from '../sync'

/**
 * Taking money back against a debt.
 *
 * Without this the nasiya half of the product is write-only: the shop can
 * record that someone owes it money and has no way to record them paying.
 *
 * Online only, like the other two writes that create something the rest of
 * the system has to address by id. A repayment queued offline would also be
 * a repayment the customer believes is settled while the balance still says
 * otherwise on every other till — the one place where an optimistic local
 * write is worse than waiting.
 */
export function DebtPayment({
  client,
  onClose,
}: {
  client: Client
  onClose: () => void
}) {
  const currencies = useLiveQuery(() => db.currencies.toArray(), [], [] as Currency[])

  // Largest debt first, so the default is the one they came in to settle.
  const debts = owedIn(client.balances)

  const [currencyId, setCurrencyId] = useState<number | null>(debts[0]?.[0] ?? null)

  const owed = currencyId === null ? 0 : (client.balances?.[currencyId] ?? 0)
  const code = currencies.find((c) => c.id === currencyId)?.code ?? ''

  const [amount, setAmount] = useState(debts[0] && debts[0][1] > 0 ? String(debts[0][1]) : '')
  const [type, setType] = useState('cash')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const online = navigator.onLine

  const value = Number(amount) || 0
  const over = value > owed && owed > 0

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      await payClientDebt({
        client_id: client.id,
        amount: value,
        // Which debt this settles. Without it the server would subtract the
        // number from the shop's own currency, and a dollar handed over
        // would clear a som.
        currency_id: currencyId,
        payment_type: type,
        note: note.trim() === '' ? null : note.trim(),
      })
      await pullAll()
      onClose()
    } catch (e) {
      setError(e instanceof Error ? e.message : "To'lovda xatolik")
      setBusy(false)
    }
  }

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>Qarz to'lovi</h2>

        <div className="totals" style={{ marginBottom: 12 }}>
          <div className="row">
            <span className="muted">{client.name}</span>
            <span className="muted">{client.phone_number ?? ''}</span>
          </div>
          <div className="row grand">
            <span>{owed >= 0 ? 'Qarzi' : 'Haqdor'}</span>
            <span>
              {formatMoney(Math.abs(owed))} {code}
            </span>
          </div>
        </div>

        {/* Shown only when there is a choice to make. One debt needs no
            picker; two do, and paying the wrong one is invisible until
            somebody reconciles the books. */}
        {debts.length > 1 && (
          <div className="field">
            <label>Qaysi qarz</label>
            <div className="seg">
              {debts.map(([id, value_]) => (
                <button
                  key={id}
                  type="button"
                  className={currencyId === id ? 'on' : ''}
                  onClick={() => {
                    setCurrencyId(id)
                    setAmount(value_ > 0 ? String(value_) : '')
                  }}
                >
                  {formatMoney(Math.abs(value_))}{' '}
                  {currencies.find((c) => c.id === id)?.code ?? ''}
                </button>
              ))}
            </div>
          </div>
        )}

        {!online && (
          <div className="notice err">
            Internet yo'q. To'lovni yozish uchun ulanish kerak — aks holda mijoz to'ladim deb
            o'ylaydi, boshqa kassalarda esa qarz turaveradi.
          </div>
        )}
        {error && <div className="notice err">{error}</div>}

        <form onSubmit={submit}>
          <div className="field">
            <label htmlFor="d-amount">Summa</label>
            <input
              id="d-amount"
              autoFocus
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              inputMode="decimal"
            />
            {/* Stated, not blocked. Someone paying more than they owe is
                ordinary — they are settling and leaving a little on account. */}
            {over && (
              <div className="hint">
                Qarzdan {formatMoney(value - owed)} {code} ko'p — farqi haqdorlik bo'lib qoladi.
              </div>
            )}
          </div>

          <div className="field">
            <label>To'lov turi</label>
            <div className="seg">
              {[
                ['cash', 'Naqd'],
                ['card', 'Karta'],
                ['terminal', 'Terminal'],
                ['bank_account', 'Hisob raqam'],
              ].map(([value_, label]) => (
                <button
                  key={value_}
                  type="button"
                  className={type === value_ ? 'on' : ''}
                  onClick={() => setType(value_)}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>

          <div className="field">
            <label htmlFor="d-note">Izoh</label>
            <input id="d-note" value={note} onChange={(e) => setNote(e.target.value)} />
          </div>

          <div className="actions">
            <button type="button" className="ghost" onClick={onClose}>
              Bekor qilish
            </button>
            <button className="primary" disabled={busy || !online || value <= 0}>
              {busy ? 'Yozilmoqda…' : `Qabul qilish: ${formatMoney(value)} ${code}`}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
