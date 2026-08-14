import { db, type Product } from './db'
import { enqueue, pushOutbox } from './sync'

/**
 * Ringing up a sale.
 *
 * The order of operations here is the whole offline design in six lines:
 *
 *   1. write the sale to the outbox        (durable — survives a closed tab)
 *   2. decrement local stock optimistically (so the next scan shows the truth
 *                                            the cashier expects)
 *   3. try to send                          (best effort; failure is normal)
 *
 * Step 3 failing is not an error state. It is Tuesday. The sale is already
 * recorded and the Sinxronlash button will carry it up later.
 */

export type CartLine = {
  product: Product
  quantity: number
  /** Cashier may override the catalogue price; the shop opted into that. */
  price: number
}

export type Payment = {
  /** null = nasiya (nothing handed over). */
  paymentType: 'cash' | 'card' | 'terminal' | 'bank_account' | null
  paidAmount: number
  discount: number
  clientId: number | null
  note: string
}

export type SaleOutcome = {
  clientOperationId: string
  total: number
  change: number
  /** false when the sale is sitting in the outbox waiting for a connection. */
  synced: boolean
  serverData: Record<string, unknown> | null
  error: string | null
}

export function lineTotal(line: CartLine): number {
  return round2(line.quantity * line.price)
}

export function cartSubtotal(lines: CartLine[]): number {
  return round2(lines.reduce((sum, line) => sum + lineTotal(line), 0))
}

export async function submitSale(
  lines: CartLine[],
  payment: Payment,
  currencyId: number,
): Promise<SaleOutcome> {
  if (lines.length === 0) throw new Error('Savatcha bo\'sh')

  const subtotal = cartSubtotal(lines)
  const discount = round2(Math.min(payment.discount, subtotal))
  const total = round2(subtotal - discount)

  if (total <= 0) throw new Error('Savdo summasi 0 dan katta bo\'lishi kerak')

  const isCredit = payment.paidAmount < total

  // Refused here rather than at the server so the cashier finds out while the
  // customer is still standing there, not at sync time hours later.
  if (isCredit && payment.clientId === null) {
    throw new Error('Nasiya sotuv uchun mijoz tanlang')
  }

  const payload = {
    currency_id: currencyId,
    client_id: payment.clientId,
    payment_type: payment.paidAmount > 0 ? payment.paymentType : null,
    paid_amount: payment.paidAmount > 0 ? payment.paidAmount : null,
    discount_amount: discount,
    note: payment.note || null,
    items: lines.map((line) => ({
      product_id: line.product.id,
      quantity: line.quantity,
      price: line.price,
    })),
  }

  const label = `Sotuv · ${lines.length} ta · ${formatMoney(total)}`
  const item = await enqueue('sale.create', payload, label)

  await decrementLocalStock(lines)

  let synced = false
  let serverData: Record<string, unknown> | null = null
  let error: string | null = null

  try {
    await pushOutbox()
    const stored = await db.outbox.get(item.seq!)
    synced = stored?.status === 'sent'
    serverData = stored?.result ?? null
    if (!synced) error = stored?.error ?? null
  } catch (e) {
    // Offline, or the server is down. Entirely expected — the sale is safe in
    // the outbox and nothing here needs to be undone.
    error = e instanceof Error ? e.message : String(e)
  }

  const change = payment.paidAmount > total ? round2(payment.paidAmount - total) : 0

  return { clientOperationId: item.client_operation_id, total, change, synced, serverData, error }
}

/**
 * Optimistic local decrement.
 *
 * Only touches products that are actually tracked (`quantity !== null`).
 * Writing 0 onto a never-inventoried product would light it up as out-of-stock
 * on this till, disagreeing with every other client of the same catalogue.
 *
 * These numbers are provisional by definition — another kassa may be selling
 * the same shelf right now. The server's response overwrites them (see
 * applyServerStock in sync.ts).
 */
async function decrementLocalStock(lines: CartLine[]): Promise<void> {
  await db.transaction('rw', db.products, async () => {
    for (const line of lines) {
      const current = await db.products.get(line.product.id)
      if (!current || current.quantity === null) continue
      await db.products.update(line.product.id, {
        quantity: round2(current.quantity - line.quantity),
      })
    }
  })
}

export function round2(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100
}

export function formatMoney(value: number): string {
  return new Intl.NumberFormat('uz-UZ', { maximumFractionDigits: 2 }).format(value)
}
