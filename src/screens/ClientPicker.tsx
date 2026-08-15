import { useMemo, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { db, type Client } from '../db'
import { WALK_IN_NAME } from '../sales'

/**
 * Choosing who the sale is for.
 *
 * Reads from the local cache, so it works with no connection — a nasiya sale to
 * a regular customer must not depend on the shop's internet. Creating a new
 * client offline is deliberately not offered here: the sale would then have to
 * carry an unsaved client through the outbox, and the common case (a stranger
 * paying cash) needs no client at all.
 */
export function ClientPicker({
  onPick,
  onClose,
}: {
  onPick: (client: Client | null) => void
  onClose: () => void
}) {
  const [query, setQuery] = useState('')

  const clients = useLiveQuery(() => db.clients.toArray(), [], [] as Client[])

  const matches = useMemo(() => {
    const alive = clients.filter((c) => !c.deleted && c.name !== WALK_IN_NAME)
    const needle = query.trim().toLowerCase()

    const list = needle
      ? alive.filter(
          (c) =>
            c.name.toLowerCase().includes(needle) ||
            (c.phone_number ?? '').replace(/\D/g, '').includes(needle.replace(/\D/g, '')),
        )
      : alive

    return list.sort((a, b) => a.name.localeCompare(b.name)).slice(0, 100)
  }, [clients, query])

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>Mijoz tanlash</h2>

        <div className="field">
          <input
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Ism yoki telefon raqam…"
          />
        </div>

        <button type="button" className="ghost" style={{ width: '100%' }} onClick={() => onPick(null)}>
          Naqd xaridor (ismsiz)
        </button>

        <div className="client-list">
          {matches.map((client) => (
            <button
              type="button"
              key={client.id}
              className="client-item"
              onClick={() => onPick(client)}
            >
              <span className="grow">
                <span className="nm">{client.name}</span>
                {client.phone_number && <span className="sub">{client.phone_number}</span>}
              </span>
              {client.is_blocked && <span className="tag">bloklangan</span>}
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
