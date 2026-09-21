import { useMemo, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { db, type Client, type Currency, type Product } from '../db'
import { formatMoney, owedIn, WALK_IN_NAME } from '../sales'
import { sortRows, type SortState } from '../sorting'
import { DebtPayment } from './DebtPayment'
import { ProductForm } from './ProductForm'
import { SortHeader } from './SortHeader'

/**
 * Read-only browsing of the two lists the seller occasionally needs to look
 * through rather than search: what is in stock, and who the customers are.
 *
 * Both read from the local cache, so they work with no connection. Neither
 * belongs on the sale screen — a wall of products there competes with the
 * basket for the space that matters — but "how many of these do we have left?"
 * is a real question and it needs somewhere to be answered.
 */

/** The columns Mahsulotlar can be ordered by. */
type ProductCol = 'name' | 'qty' | 'price'

export function Products() {
  const [query, setQuery] = useState('')
  const [onlyTracked, setOnlyTracked] = useState(false)
  const [sort, setSort] = useState<SortState<ProductCol>>(null)

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
      // By name unless the seller has asked for something else. Alphabetical
      // is the order you can find a thing in when you already know what it
      // is called, which is the common case; the columns are for the other
      // questions — what is nearly out, what is expensive.
      .sort((a, b) => (a.name ?? '').localeCompare(b.name ?? '', 'uz'))
  }, [products, query, onlyTracked])

  const sorted = useMemo(
    () =>
      sortRows(rows, sort, (product, key) => {
        if (key === 'name') return product.name
        if (key === 'qty') return product.quantity
        return product.price
      }),
    [rows, sort],
  )

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

      {/* Hidden when there is nothing to order — a header over an empty list
          is four controls that do nothing. */}
      <div className="view-body">
        {rows.length > 0 && (
          <div className="list-head">
            {/* Three headings for three columns. The code sits UNDER the name
                rather than beside it, so a "Kod" heading here would point at
                nothing — the row has no such column to head. */}
            <SortHeader label="Nomi" column="name" state={sort} onChange={setSort} />
            <SortHeader
              label="Qoldiq"
              column="qty"
              state={sort}
              onChange={setSort}
              align="right"
              title="Hisobga olinmagan mahsulotlar oxirida"
            />
            <SortHeader label="Narx" column="price" state={sort} onChange={setSort} align="right" />
          </div>
        )}
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

        {sorted.map((product) => {
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
              {/* A missing price is not zero. Printed as 0 it reads as free,
                  in the column somebody scans to find what is mispriced. */}
              <span className="amt">
                {product.price == null ? '—' : formatMoney(product.price)}
              </span>
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

/** The columns Mijozlar can be ordered by. */
type ClientCol = 'name' | 'debt'

export function Clients({ shopCurrencyId }: { shopCurrencyId: number | null }) {
  const [query, setQuery] = useState('')
  const [paying, setPaying] = useState<Client | null>(null)
  const [sort, setSort] = useState<SortState<ClientCol>>(null)

  const clients = useLiveQuery(() => db.clients.toArray(), [], [] as Client[])
  const currencies = useLiveQuery(() => db.currencies.toArray(), [], [] as Currency[])

  const code = (id: number) => currencies.find((c) => c.id === id)?.code ?? ''

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
      .sort((a, b) => a.name.localeCompare(b.name, 'uz'))
  }, [clients, query])

  /**
   * Ordered by whichever column was clicked.
   *
   * "Qarz" sorts by the balance in the SHOP'S OWN currency and nothing else.
   * A debt of $11 and a debt of 12,000 so'm cannot be put in one order
   * without an exchange rate this till does not have, and ranking them by
   * the bare number would put the dollar debt near the bottom of "who owes
   * most" — which is the same class of mistake as adding them together. A
   * client who owes only in another currency has nothing in this column, so
   * they sort to the end, and their badge still shows what they owe.
   */
  const sorted = useMemo(
    () =>
      sortRows(rows, sort, (client, key) => {
        if (key === 'name') return client.name
        return shopCurrencyId === null ? null : (client.balances?.[shopCurrencyId] ?? null)
      }),
    [rows, sort, shopCurrencyId],
  )

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
          // The number the owner actually opens this screen for — one per
          // currency. Totalled across them it would be a figure that is not
          // money, printed in the place they trust most.
          const totals = new Map<number, number>()
          let debtors = 0

          for (const client of rows) {
            const debts = owedIn(client.balances).filter(([, amount]) => amount > 0)
            if (debts.length > 0) debtors++
            for (const [currencyId, amount] of debts) {
              totals.set(currencyId, (totals.get(currencyId) ?? 0) + amount)
            }
          }

          if (totals.size === 0) return null

          return (
            <span style={{ color: 'var(--danger-text)' }}>
              {' '}· {debtors} ta qarzdor, jami{' '}
              {[...totals.entries()]
                .map(([currencyId, sum]) => `${formatMoney(sum)} ${code(currencyId)}`)
                .join(' · ')}
            </span>
          )
        })()}
      </div>

      <div className="view-body">
        {rows.length > 0 && (
          <div className="list-head">
            <SortHeader label="Ism" column="name" state={sort} onChange={setSort} />
            <SortHeader
              label="Qarz"
              column="debt"
              state={sort}
              onChange={setSort}
              align="right"
              title="Do'kon valyutasidagi qarz bo'yicha. Boshqa valyutadagilar oxirida."
            />
          </div>
        )}
        {rows.length === 0 && (
          <div className="cart-empty">
            {clients.length === 0
              ? 'Mijozlar hali yuklanmagan. "Sinxronlash" tugmasini bosing.'
              : 'Mijoz topilmadi'}
          </div>
        )}

        {sorted.map((client) => (
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
            {owedIn(client.balances).map(([currencyId, amount]) => (
              <span key={currencyId} className={`tag ${amount > 0 ? 'debt' : 'credit'}`}>
                {amount > 0
                  ? `${formatMoney(amount)} ${code(currencyId)} qarz`
                  : `${formatMoney(-amount)} ${code(currencyId)} haqdor`}
              </span>
            ))}
          </div>
        ))}
      </div>

      {paying && <DebtPayment client={paying} onClose={() => setPaying(null)} />}
    </div>
  )
}
