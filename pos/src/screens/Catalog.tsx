import { useMemo, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { db, type Client, type Product } from '../db'
import { formatMoney, WALK_IN_NAME } from '../sales'
import { DebtPayment } from './DebtPayment'
import { ProductForm } from './ProductForm'

/**
 * Read-only browsing of the two lists the seller occasionally needs to look
 * through rather than search: what is in stock, and who the customers are.
 *
 * Both read from the local cache, so they work with no connection. Neither
 * belongs on the sale screen — a wall of products there competes with the
 * basket for the space that matters — but "how many of these do we have left?"
 * is a real question and it needs somewhere to be answered.
 */

export function Products() {
  const [query, setQuery] = useState('')
  const [onlyTracked, setOnlyTracked] = useState(false)

  // null = closed, 'new' = creating, Product = editing that one.
  const [editing, setEditing] = useState<Product | 'new' | null>(null)

  const products = useLiveQuery(() => db.products.toArray(), [], [] as Product[])

  const rows = useMemo(() => {
    const needle = query.trim().toLowerCase()

    return products
      .filter((p) => !p.deleted)
      .filter((p) => (onlyTracked ? p.quantity !== null : true))
      .filter(
        (p) =>
          needle === '' ||
          (p.name ?? '').toLowerCase().includes(needle) ||
          (p.code ?? '').toLowerCase().includes(needle) ||
          (p.barcode ?? '').toLowerCase().includes(needle),
      )
      .sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''))
  }, [products, query, onlyTracked])

  const outOfStock = rows.filter((p) => p.quantity !== null && p.quantity <= 0).length

  return (
    <div className="view">
      <div className="view-head">
        <h2>Mahsulotlar</h2>
        <input
          style={{ maxWidth: 320 }}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Nomi, kodi yoki barcode…"
        />
        <span className="spacer" />
        <button className={onlyTracked ? 'primary' : 'ghost'} onClick={() => setOnlyTracked((v) => !v)}>
          Faqat hisobdagilar
        </button>
        <button className="primary" onClick={() => setEditing('new')}>
          + Yangi mahsulot
        </button>
      </div>

      <div className="view-summary">
        {rows.length} ta mahsulot
        {outOfStock > 0 && (
          <span style={{ color: 'var(--danger)' }}> · {outOfStock} tasi tugagan</span>
        )}
      </div>

      <div className="view-body">
        {rows.length === 0 && (
          <div className="cart-empty">
            {products.length === 0 ? (
              <>
                {/* A brand-new shop has an empty catalogue and nothing to sell.
                    Telling it to press Sinxronlash is the wrong instruction —
                    there is nothing on the server either. */}
                <div>Hali mahsulot yo'q.</div>
                <button
                  className="primary"
                  style={{ marginTop: 12 }}
                  onClick={() => setEditing('new')}
                >
                  Birinchi mahsulotni qo'shish
                </button>
              </>
            ) : (
              'Mahsulot topilmadi'
            )}
          </div>
        )}

        {rows.map((product) => {
          const qty = product.quantity
          const state = qty === null ? '' : qty <= 0 ? 'out' : qty <= (product.low_stock_threshold ?? 0) ? 'low' : ''

          return (
            <div
              className="list-row tappable"
              key={product.id}
              role="button"
              tabIndex={0}
              onClick={() => setEditing(product)}
              onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && setEditing(product)}
            >
              <span className="grow">
                <span className="nm">{product.name}</span>
                <span className="sub">{product.barcode ?? product.code ?? '—'}</span>
              </span>
              <span className={`qty ${state}`}>
                {/* null is "never inventoried", not zero — showing 0 would flag
                    most of a typical catalogue as out of stock. */}
                {qty === null ? '—' : formatMoney(qty)}
              </span>
              <span className="amt">{formatMoney(product.price ?? 0)}</span>
            </div>
          )
        })}
      </div>

      {editing !== null && (
        <ProductForm
          product={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
        />
      )}
    </div>
  )
}

export function Clients() {
  const [query, setQuery] = useState('')
  const [paying, setPaying] = useState<Client | null>(null)

  const clients = useLiveQuery(() => db.clients.toArray(), [], [] as Client[])

  const rows = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const digits = needle.replace(/\D/g, '')

    return clients
      .filter((c) => !c.deleted && c.name !== WALK_IN_NAME)
      .filter(
        (c) =>
          needle === '' ||
          c.name.toLowerCase().includes(needle) ||
          (digits !== '' && (c.phone_number ?? '').replace(/\D/g, '').includes(digits)),
      )
      .sort((a, b) => a.name.localeCompare(b.name))
  }, [clients, query])

  return (
    <div className="view">
      <div className="view-head">
        <h2>Mijozlar</h2>
        <input
          style={{ maxWidth: 320 }}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Ism yoki telefon…"
        />
      </div>

      <div className="view-summary">
        {rows.length} ta mijoz
        {(() => {
          // The number the owner actually opens this screen for.
          const owed = rows.reduce((sum, c) => sum + Math.max(0, c.balance ?? 0), 0)
          const debtors = rows.filter((c) => (c.balance ?? 0) > 0).length
          return owed > 0 ? (
            <span style={{ color: 'var(--danger-text)' }}>
              {' '}· {debtors} ta qarzdor, jami {formatMoney(owed)}
            </span>
          ) : null
        })()}
      </div>

      <div className="view-body">
        {rows.length === 0 && (
          <div className="cart-empty">
            {clients.length === 0
              ? 'Mijozlar hali yuklanmagan. "Sinxronlash" tugmasini bosing.'
              : 'Mijoz topilmadi'}
          </div>
        )}

        {rows.map((client) => (
          <div
            className="list-row tappable"
            key={client.id}
            role="button"
            tabIndex={0}
            onClick={() => setPaying(client)}
            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && setPaying(client)}
          >
            <span className="grow">
              <span className="nm">{client.name}</span>
              <span className="sub">{client.phone_number ?? "Telefon yo'q"}</span>
            </span>
            {typeof client.balance === 'number' && client.balance !== 0 && (
              <span className={`tag ${client.balance > 0 ? 'debt' : 'credit'}`}>
                {client.balance > 0
                  ? `${formatMoney(client.balance)} qarz`
                  : `${formatMoney(-client.balance)} haqdor`}
              </span>
            )}
            {client.is_blocked && <span className="tag danger">bloklangan</span>}
          </div>
        ))}
      </div>

      {paying && <DebtPayment client={paying} onClose={() => setPaying(null)} />}
    </div>
  )
}
