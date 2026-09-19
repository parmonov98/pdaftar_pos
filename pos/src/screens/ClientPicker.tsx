import { useMemo, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { createClient } from '../api'
import { db, type Client, type Currency } from '../db'
import { formatMoney, owedIn, WALK_IN_NAME } from '../sales'
import { pullAll } from '../sync'

/**
 * Choosing who the sale is for.
 *
 * Reads from the local cache, so it works with no connection — a nasiya sale to
 * a regular customer must not depend on the shop's internet.
 *
 * Adding one needs a connection, and the form says so instead of failing at
 * submit: the sale would otherwise carry an unsaved client through the outbox
 * and name somebody the server has never heard of. It never blocks a queue,
 * because the common case — a stranger paying cash — needs no client at all.
 */
export function ClientPicker({
  onPick,
  onClose,
}: {
  onPick: (client: Client | null) => void
  onClose: () => void
}) {
  const [query, setQuery] = useState('')
  const [cursor, setCursor] = useState(0)
  const [adding, setAdding] = useState(false)
  const [newName, setNewName] = useState('')
  const [newPhone, setNewPhone] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const online = navigator.onLine

  const clients = useLiveQuery(() => db.clients.toArray(), [], [] as Client[])
  const currencies = useLiveQuery(() => db.currencies.toArray(), [], [] as Currency[])

  const code = (id: number) => currencies.find((c) => c.id === id)?.code ?? ''

  const matches = useMemo(() => {
    const alive = clients.filter((c) => !c.deleted && c.name !== WALK_IN_NAME)
    const needle = query.trim().toLowerCase()

    // Digits only, and only when there are any. A needle of "ali" reduces to
    // "" here, and every string contains "", so the phone branch matched
    // every customer in the shop and searching by name returned the whole
    // list unfiltered — the filter looked like it was working and was not.
    const digits = needle.replace(/\D/g, '')

    const list = needle
      ? alive.filter(
          (c) =>
            c.name.toLowerCase().includes(needle) ||
            (digits !== '' && (c.phone_number ?? '').replace(/\D/g, '').includes(digits)),
        )
      : alive

    return list.sort((a, b) => a.name.localeCompare(b.name)).slice(0, 100)
  }, [clients, query])

  async function save(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      const created = await createClient({
        name: newName.trim(),
        phone_number: newPhone.trim() === '' ? null : newPhone.trim(),
      })

      // Pull before handing it back, so the caller gets a real cached row
      // rather than a stub that the next sync would overwrite.
      await pullAll()
      const stored = await db.clients.get(created.id)
      onPick(stored ?? { ...created, phone_number: null, address: null, updated_at: null })
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Saqlashda xatolik')
      setBusy(false)
    }
  }

  if (adding) {
    return (
      <div className="overlay" onClick={onClose}>
        <div className="modal" onClick={(e) => e.stopPropagation()}>
          <h2>Yangi mijoz</h2>

          {!online && (
            <div className="notice err">
              Internet yo'q. Mijoz qo'shish uchun ulanish kerak — naqd sotuvga mijoz shart emas.
            </div>
          )}
          {error && <div className="notice err">{error}</div>}

          <form onSubmit={save}>
            <div className="field">
              <label htmlFor="c-name">Ismi</label>
              <input
                id="c-name"
                autoFocus
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="Sobir aka"
              />
            </div>

            <div className="field">
              <label htmlFor="c-phone">Telefon raqam</label>
              <input
                id="c-phone"
                value={newPhone}
                onChange={(e) => setNewPhone(e.target.value)}
                placeholder="+998 90 123 45 67"
                inputMode="tel"
              />
              {/* The number is how a debt gets chased, so it is worth asking
                  for — but a regular whose number nobody has is still better
                  recorded than not recorded. */}
              <div className="hint">Qarzni eslatish uchun kerak bo'ladi.</div>
            </div>

            <div className="actions">
              <button type="button" className="ghost" onClick={() => setAdding(false)}>
                Orqaga
              </button>
              <button className="primary" disabled={busy || !online || newName.trim() === ''}>
                {busy ? 'Saqlanmoqda…' : 'Saqlash'}
              </button>
            </div>
          </form>
        </div>
      </div>
    )
  }

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>Mijoz tanlash</h2>

        <div className="field">
          {/* Arrows and Enter, like the product browser next to it. Picking a
              customer sits in the middle of a keyboard-only sale, and having
              to reach for the mouse here breaks the run for the one kind of
              sale — nasiya — that always needs a name. */}
          <input
            autoFocus
            value={query}
            onChange={(e) => {
              setQuery(e.target.value)
              setCursor(0)
            }}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown') {
                e.preventDefault()
                setCursor((c) => Math.min(c + 1, matches.length - 1))
              } else if (e.key === 'ArrowUp') {
                e.preventDefault()
                setCursor((c) => Math.max(c - 1, 0))
              } else if (e.key === 'Enter') {
                e.preventDefault()
                const picked = matches[cursor]
                if (picked) onPick(picked)
              } else if (e.key === 'Escape') {
                e.preventDefault()
                onClose()
              }
            }}
            placeholder="Ism yoki telefon raqam…"
          />
        </div>

        <div className="two-col">
          <button type="button" className="ghost" onClick={() => onPick(null)}>
            Naqd xaridor (ismsiz)
          </button>
          <button type="button" className="primary" onClick={() => setAdding(true)}>
            + Yangi mijoz
          </button>
        </div>

        <div className="client-list">
          {matches.map((client, index) => (
            <button
              type="button"
              key={client.id}
              className={`client-item ${index === cursor ? 'on' : ''}`}
              onClick={() => onPick(client)}
            >
              <span className="grow">
                <span className="nm">{client.name}</span>
                {client.phone_number && <span className="sub">{client.phone_number}</span>}
              </span>
              {/* One badge per currency. A single merged figure would be
                  arithmetic across currencies, which is not money. */}
              {owedIn(client.balances).map(([currencyId, amount]) => (
                <span key={currencyId} className={`tag ${amount > 0 ? 'debt' : 'credit'}`}>
                  {amount > 0
                    ? `${formatMoney(amount)} ${code(currencyId)} qarz`
                    : `${formatMoney(-amount)} ${code(currencyId)} haqdor`}
                </span>
              ))}
            </button>
          ))}

          {matches.length === 0 && (
            <div className="hint" style={{ padding: 12 }}>
              {clients.length === 0
                ? "Mijozlar hali yuklanmagan. \"Sinxronlash\" tugmasini bosing."
                : 'Mijoz topilmadi'}
            </div>
          )}
        </div>

        <button type="button" className="ghost" style={{ width: '100%', marginTop: 10 }} onClick={onClose}>
          Yopish
        </button>
      </div>
    </div>
  )
}
