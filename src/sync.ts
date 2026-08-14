import {
  db,
  getMeta,
  setMeta,
  type Category,
  type Client,
  type Currency,
  type OutboxItem,
  type Product,
  type Supplier,
  type Unit,
} from './db'
import {
  pullCatalog,
  pushOperations,
  type OperationResult,
  type PushEnvelope,
} from './api'

/**
 * The two halves of "Sinxronlash".
 *
 * Order matters and is not negotiable: PUSH first, then PULL. The outbox holds
 * data that exists nowhere else; the catalogue is a copy we can always fetch
 * again. If the connection dies halfway through a sync, the half that must have
 * completed is the one carrying the sales.
 */

const CURSOR_KEY = 'catalog_cursor'
const LAST_SYNC_KEY = 'last_sync_at'

/** Matches the server's MAX_BATCH. Larger requests are refused outright. */
const BATCH = 200

type Cursor = { since: string | null; sinceId: number | null }

export type SyncReport = {
  pushed: number
  failed: number
  errored: number
  pulled: number
  message: string
}

/**
 * Queue a write. This is how EVERY POS write starts — including when the till
 * is online and the network is fine.
 *
 * Writing to the outbox first and sending second is what makes a dropped
 * connection a non-event: the sale is already durable before any packet leaves
 * the machine. The alternative — send, and queue only on failure — loses the
 * sale whenever the tab is closed during the request.
 */
export async function enqueue(
  type: string,
  payload: unknown,
  label: string,
): Promise<OutboxItem> {
  const item: OutboxItem = {
    // Minted HERE, at the moment of the cashier's action. If this were
    // generated at send time, a retry would carry a new id and the server
    // would have no way to recognise it as the same sale.
    client_operation_id: crypto.randomUUID(),
    type,
    occurred_at: new Date().toISOString(),
    payload,
    status: 'queued',
    attempts: 0,
    error: null,
    result: null,
    label,
    created_at: new Date().toISOString(),
  }

  const seq = await db.outbox.add(item)
  return { ...item, seq: seq as number }
}

/** Operations still owed to the server, oldest first. */
export function pendingQuery() {
  return db.outbox.where('status').anyOf('queued', 'failed')
}

export async function pendingCount(): Promise<number> {
  return pendingQuery().count()
}

/**
 * Send the outbox.
 *
 * Sent in insertion order, in one batch, because the server resolves
 * within-batch references (`product.create` then a sale of that product) by
 * position. Splitting or reordering would break that chain.
 */
export async function pushOutbox(): Promise<{ pushed: number; failed: number; errored: number }> {
  const items = await pendingQuery().sortBy('seq')

  if (items.length === 0) return { pushed: 0, failed: 0, errored: 0 }

  const slice = items.slice(0, BATCH)

  const envelopes: PushEnvelope[] = slice.map((item) => ({
    client_operation_id: item.client_operation_id,
    type: item.type,
    occurred_at: item.occurred_at,
    payload: item.payload,
  }))

  const response = await pushOperations(envelopes)

  const byId = new Map<string, OperationResult>(
    response.results.map((r) => [r.client_operation_id, r]),
  )

  let pushed = 0
  let failed = 0
  let errored = 0

  for (const item of slice) {
    const result = byId.get(item.client_operation_id)

    if (!result) {
      // The server did not mention this operation at all. Leave it queued —
      // dropping it would silently discard a sale, and marking it applied
      // would be a lie.
      continue
    }

    if (result.status === 'applied') {
      pushed++
      await db.outbox.update(item.seq!, {
        status: 'sent',
        result: result.data,
        error: null,
        attempts: item.attempts + 1,
      })
      await applyServerStock(result)
      continue
    }

    // The server distinguishes "refused before writing" (retry me) from "state
    // unknown" (do NOT retry). We mirror that distinction here rather than
    // flattening both into "failed" — resending an unknown-state sale is
    // exactly how a till double-charges someone.
    const unknownState = (result.error ?? '').includes('/sync/status')

    if (unknownState) {
      errored++
      await db.outbox.update(item.seq!, {
        status: 'error',
        error: result.error,
        attempts: item.attempts + 1,
      })
    } else {
      failed++
      await db.outbox.update(item.seq!, {
        status: 'failed',
        error: result.error,
        attempts: item.attempts + 1,
      })
    }
  }

  return { pushed, failed, errored }
}

/**
 * Overwrite local stock with the figures the server reported.
 *
 * The till decremented optimistically when the cashier rang the sale up; that
 * number was a guess that ignored every other kassa in the shop. This replaces
 * the guess with the ledger's answer, which is the only reason two tills
 * selling the same shelf converge instead of drifting apart all day.
 */
async function applyServerStock(result: OperationResult): Promise<void> {
  const stock = (result.data as { stock?: Array<{ product_id: number; quantity: number | null }> })
    .stock

  if (!Array.isArray(stock)) return

  await db.transaction('rw', db.products, async () => {
    for (const row of stock) {
      const product = await db.products.get(row.product_id)
      if (product) await db.products.update(row.product_id, { quantity: row.quantity })
    }
  })
}

/**
 * Bring the catalogue down, following the cursor until the server says it is
 * finished.
 *
 * The cursor is persisted after EACH page, not at the end. A sync interrupted
 * on page 7 of 20 resumes at page 8 rather than starting over, which on a bad
 * connection is the difference between eventually finishing and never
 * finishing.
 */
export async function pullAll(): Promise<number> {
  let cursor = await getMeta<Cursor>(CURSOR_KEY, { since: null, sinceId: null })
  let total = 0
  // Bounded so a server that always answers has_more (a bug, or a catalogue
  // being edited faster than we can read it) cannot spin the tab forever.
  let pages = 0

  while (pages < 100) {
    pages++

    const response = await pullCatalog({
      since: cursor.since,
      sinceId: cursor.sinceId,
      limit: 500,
    })

    total += await absorb(response.data)

    cursor = { since: response.next_since, sinceId: response.next_since_id }
    await setMeta(CURSOR_KEY, cursor)

    if (!response.has_more) break
  }

  await setMeta(LAST_SYNC_KEY, new Date().toISOString())
  return total
}

async function absorb(data: Record<string, unknown[]>): Promise<number> {
  let count = 0

  const products = (data.products ?? []) as Product[]
  if (products.length) {
    // Tombstones are removals, not rows to store. Without honouring them a
    // deleted product stays scannable on this till forever.
    const [alive, dead] = partition(products, (p) => !p.deleted)
    await db.products.bulkPut(alive)
    if (dead.length) await db.products.bulkDelete(dead.map((p) => p.id))
    count += products.length
  }

  const clients = (data.clients ?? []) as Client[]
  if (clients.length) {
    const [alive, dead] = partition(clients, (c) => !c.deleted)
    await db.clients.bulkPut(alive)
    if (dead.length) await db.clients.bulkDelete(dead.map((c) => c.id))
    count += clients.length
  }

  const suppliers = (data.suppliers ?? []) as Supplier[]
  if (suppliers.length) {
    const [alive, dead] = partition(suppliers, (s) => !s.deleted)
    await db.suppliers.bulkPut(alive)
    if (dead.length) await db.suppliers.bulkDelete(dead.map((s) => s.id))
    count += suppliers.length
  }

  if (data.units?.length) await db.units.bulkPut(data.units as Unit[])
  if (data.currencies?.length) await db.currencies.bulkPut(data.currencies as Currency[])
  if (data.income_categories?.length)
    await db.incomeCategories.bulkPut(data.income_categories as Category[])
  if (data.expense_categories?.length)
    await db.expenseCategories.bulkPut(data.expense_categories as Category[])

  return count
}

function partition<T>(items: T[], predicate: (item: T) => boolean): [T[], T[]] {
  const yes: T[] = []
  const no: T[] = []
  for (const item of items) (predicate(item) ? yes : no).push(item)
  return [yes, no]
}

/** The Sinxronlash button. Push first — see the note at the top of this file. */
export async function syncNow(): Promise<SyncReport> {
  const push = await pushOutbox()
  const pulled = await pullAll()

  const parts: string[] = []
  if (push.pushed) parts.push(`${push.pushed} ta amal yuborildi`)
  if (push.failed) parts.push(`${push.failed} ta rad etildi`)
  if (push.errored) parts.push(`${push.errored} ta xatolik (tekshirish kerak)`)
  parts.push(`${pulled} ta yozuv yangilandi`)

  return { ...push, pulled, message: parts.join(', ') }
}

export function lastSyncAt(): Promise<string | null> {
  return getMeta<string | null>(LAST_SYNC_KEY, null)
}
