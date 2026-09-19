import Dexie, { type Table } from 'dexie'

/**
 * The till's local database.
 *
 * Two things live here and they are not the same kind of thing:
 *
 *   CACHE (products, clients, suppliers, units, currencies) — copies of rows
 *   that belong to pDaftar. Safe to delete at any time; the next pull restores
 *   them. Never the authority on anything, least of all stock.
 *
 *   OUTBOX (outbox) — writes the cashier made that the server has not accepted
 *   yet. This is the ONLY data in the browser that exists nowhere else. Losing
 *   it loses real sales, so it is written before the network is ever touched
 *   and only cleared once the server has confirmed the operation.
 *
 * Keeping the two mentally separate is what makes the offline story simple:
 * "clear the cache" is always safe, "clear the outbox" never is.
 */

/** One way a product can be sold, and what it is worth in base units. */
export type ProductUnitOption = {
  id: number
  unit_id: number
  /** quantity x numerator / denominator = base units. Integers, so a third
   *  of a box stays exact and three of them add back up to one. */
  numerator: number
  denominator: number
  is_base: boolean
  is_active: boolean
}

export type ProductPriceRow = {
  product_unit_id: number
  currency_id: number
  type: string
  amount: number
}

export type Product = {
  id: number
  name: string | null
  code: string | null
  barcode: string | null
  price: number | null
  /** null = never inventoried. Deliberately NOT 0 — see the backend's StockService. */
  quantity: number | null
  unit_id: number | null
  currency_id: number | null
  supplier_id: number | null
  low_stock_threshold: number | null
  image_url?: string | null
  /** Every unit this can be sold in. Empty for a single-unit product. */
  units?: ProductUnitOption[]
  prices?: ProductPriceRow[]
  deleted?: boolean
  updated_at: string | null
}

export type Client = {
  id: number
  name: string
  phone_number: string | null
  address: string | null
  /** What they owe. Computed by the server from sales and payments. */
  balance?: number
  is_blocked?: boolean
  deleted?: boolean
  updated_at: string | null
}

export type Supplier = {
  id: number
  name: string
  phone_number: string | null
  deleted?: boolean
  updated_at: string | null
}

export type Unit = { id: number; name: string; short_name: string | null; is_default: boolean }
export type Currency = { id: number; name: string | null; code: string | null; sign: string | null }
export type Category = { id: number; name: string }

export type OutboxStatus =
  /** Waiting to be sent. */
  | 'queued'
  /** Server accepted it. Kept for the receipt list, not for retrying. */
  | 'sent'
  /** Server refused it before writing anything — safe to send again. */
  | 'failed'
  /**
   * Server blew up in a way it could not prove left the database untouched.
   * NEVER auto-retried: resending could ring the same sale up twice. Needs a
   * human to look at /sync/status and decide.
   */
  | 'error'

export type OutboxItem = {
  seq?: number
  /** Minted when the cashier acted, not when we send. This is the idempotency key. */
  client_operation_id: string
  type: string
  occurred_at: string
  payload: unknown
  status: OutboxStatus
  attempts: number
  error: string | null
  /** The server's response once applied — backs the receipt view. */
  result: Record<string, unknown> | null
  /** Human label for the queue list, so a pending row is readable without decoding the payload. */
  label: string
  /**
   * Printable snapshot, on sale rows only.
   *
   * Kept here because the product NAMES exist nowhere else once the sale
   * closes — the payload carries ids and the draft that held the names is
   * gone. Without it a receipt could not be reprinted, least of all for a sale
   * that never reached the server.
   */
  receipt?: import('./receipt').Receipt
  created_at: string
}

export type MetaRow = { key: string; value: unknown }

export type DraftLine = {
  productId: number
  /** Snapshot, so a line still reads correctly if the product is later deleted. */
  name: string
  quantity: number
  price: number
  /**
   * Which unit this line is sold in. Null means the product's base unit,
   * which is also what every line written before multi-unit existed means —
   * so an open draft from yesterday keeps working.
   */
  productUnitId?: number | null
}

/**
 * An open, unfinished sale.
 *
 * A counter serves more than one customer at a time: someone is halfway through
 * a basket, steps aside to find a size, and the next person is already putting
 * things down. Holding only one basket forces the seller to either clear it or
 * make the second customer wait, and both lose sales.
 *
 * Persisted rather than kept in memory because an unfinished basket is real
 * work — a reload, a crashed tab, or a battery death must not silently discard
 * ten scanned items.
 */
export type SaleDraft = {
  id: string
  /** Human label on the tab, e.g. "15.08-#2". */
  name: string
  createdAt: string
  lines: DraftLine[]
  clientId: number | null
  clientName: string | null
  discountValue: string
  discountMode: 'percent' | 'amount'
  currencyId: number
}

class PosDb extends Dexie {
  products!: Table<Product, number>
  clients!: Table<Client, number>
  suppliers!: Table<Supplier, number>
  units!: Table<Unit, number>
  currencies!: Table<Currency, number>
  incomeCategories!: Table<Category, number>
  expenseCategories!: Table<Category, number>
  outbox!: Table<OutboxItem, number>
  drafts!: Table<SaleDraft, string>
  meta!: Table<MetaRow, string>

  constructor() {
    super('pdaftar-pos')
    this.version(2).stores({
      products: 'id, barcode, code, name',
      clients: 'id, name, phone_number',
      suppliers: 'id, name',
      units: 'id',
      currencies: 'id',
      incomeCategories: 'id',
      expenseCategories: 'id',
      outbox: '++seq, client_operation_id, status, type, created_at',
      // Ordered by creation so the tab strip reads left-to-right in the order
      // the seller opened them.
      drafts: 'id, createdAt',
      meta: 'key',
    })

    this.version(1).stores({
      // Indexed on barcode and code because those are the scanner's two
      // lookup paths and they run on every scanned item.
      products: 'id, barcode, code, name',
      clients: 'id, name, phone_number',
      suppliers: 'id, name',
      units: 'id',
      currencies: 'id',
      incomeCategories: 'id',
      expenseCategories: 'id',
      // Auto-incrementing seq preserves the order the cashier acted in, which
      // the server relies on: a product created offline must be pushed before
      // the sale that references it.
      outbox: '++seq, client_operation_id, status, type, created_at',
      meta: 'key',
    })
  }
}

export const db = new PosDb()

export async function getMeta<T>(key: string, fallback: T): Promise<T> {
  const row = await db.meta.get(key)
  return row === undefined ? fallback : (row.value as T)
}

export async function setMeta(key: string, value: unknown): Promise<void> {
  await db.meta.put({ key, value })
}

/**
 * Wipe the cache but KEEP the outbox.
 *
 * Offered as a recovery action for "my catalogue looks wrong". It must never
 * touch pending operations — a cashier reaching for a repair button should not
 * be able to delete the morning's unsent sales with it.
 */
export async function clearCache(): Promise<void> {
  await Promise.all([
    db.products.clear(),
    db.clients.clear(),
    db.suppliers.clear(),
    db.units.clear(),
    db.currencies.clear(),
    db.incomeCategories.clear(),
    db.expenseCategories.clear(),
  ])
  await db.meta.delete('catalog_cursor')
}
