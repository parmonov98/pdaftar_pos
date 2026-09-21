import { db, type OutboxItem } from './db'
import type { RecentSale } from './api'
import { round2, type CartLine, type SalePart } from './sales'

/**
 * The POS's own receipt.
 *
 * Deliberately NOT pDaftar's nakladnoy. That one is a PDF the server renders
 * from a Debt, for the mobile app's wholesale flow — a different document for a
 * different audience, and a paid counter sale has no Debt to render from anyway.
 *
 * Built in the browser rather than fetched, for one reason that decides it: a
 * sale rung up offline still has to hand the customer a receipt. A server-drawn
 * receipt cannot exist until the sale syncs, which may be hours. Everything
 * printed here is data the till already has at the moment of the sale.
 *
 * Consequence to know: the receipt number for an unsynced sale is the till's own
 * operation id, not a server sale id, because the server has not issued one yet.
 * It is shown short and prefixed so nobody mistakes it for a sale number.
 */

export type ReceiptLine = {
  name: string
  quantity: number
  price: number
  total: number
  /**
   * What the quantity is counted in. Optional because sales printed before
   * multi-unit existed have none stored, and a reprint of one of those must
   * still work.
   */
  unit?: string | null
}

export type Receipt = {
  /** What to print as the receipt number. */
  no: string
  /** paid = money taken; credit = nasiya; pending = paid but not yet synced. */
  kind: 'paid' | 'credit' | 'pending'
  shopName: string
  sellerName: string
  occurredAt: string
  lines: ReceiptLine[]
  subtotal: number
  discount: number
  total: number
  paymentType: string | null
  paid: number
  change: number
  owed: number
  clientName: string | null
  currency: string
}

export const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Naqd',
  card: 'Karta',
  terminal: 'Terminal',
  bank_account: 'Hisob raqam',
}

/**
 * Snapshot a receipt at the moment of the sale.
 *
 * Taken from the cart, not from the server's response, because the product names
 * only exist locally — the sale payload carries ids and the draft that held the
 * names is closed as soon as the sale completes. Stored on the outbox row so the
 * receipt can be reprinted later, including for a sale that never reached the
 * server.
 */
export function receiptFromSale(
  /** ONE currency's worth of the basket — see submitSale. */
  part: SalePart,
  lines: CartLine[],
  context: {
    shopName: string
    sellerName: string
    clientName: string | null
    currency: string
    paymentType: string | null
    discount: number
    /** Passed in rather than inferred — the caller is the only place that knows. */
    isCredit: boolean
    /**
     * Unit label per product unit id. Only the screen knows these — the units
     * table is not reachable from here — and without them a box and a bottle
     * print identically as "1 ×", which is the one thing a customer holding
     * the paper needs to be able to tell apart.
     */
    unitNames?: Record<number, string>
  },
): Receipt {
  const subtotal = round2(lines.reduce((sum, l) => sum + l.quantity * l.price, 0))
  // `data.sale.id`, not `data.sale_id`. The server answers
  // `{sale: {...}}` — the flat key never existed, so this was always
  // undefined and every receipt printed the local L- number even for a sale
  // the server had already numbered. The shop's own sale number is the one
  // a customer quotes when they come back.
  const serverId = (part.serverData?.sale as { id?: number } | undefined)?.id

  return {
    no: part.synced && serverId != null
      ? String(serverId)
      // Not a sale number — the server has not issued one. Prefixed and
      // shortened so it cannot be read as one.
      : `L-${part.clientOperationId.slice(0, 8).toUpperCase()}`,
    // Nasiya first: an unpaid sale is a debt whether or not it has synced yet.
    // `pending` is only for a PAID sale still sitting in the outbox — worth
    // saying on the paper, because the money is in the drawer but the books do
    // not know about it yet.
    kind: context.isCredit ? 'credit' : part.synced ? 'paid' : 'pending',
    shopName: context.shopName,
    sellerName: context.sellerName,
    occurredAt: new Date().toISOString(),
    lines: lines.map((l) => ({
      name: l.product.name ?? `#${l.product.id}`,
      quantity: l.quantity,
      price: l.price,
      total: round2(l.quantity * l.price),
      unit: l.productUnitId == null ? null : (context.unitNames?.[l.productUnitId] ?? null),
    })),
    subtotal,
    discount: context.discount,
    total: part.total,
    paymentType: context.paymentType,
    paid: context.isCredit ? 0 : round2(part.total + part.change),
    change: part.change,
    owed: context.isCredit ? part.total : 0,
    clientName: context.clientName,
    currency: context.currency,
  }
}

/** Store the printable snapshot on the outbox row, so it can be reprinted. */
export async function attachReceipt(seq: number, receipt: Receipt): Promise<void> {
  await db.outbox.update(seq, { receipt })
}

export function receiptOf(item: OutboxItem): Receipt | null {
  return item.receipt ?? null
}

/**
 * Rebuild a receipt from a server history row.
 *
 * Used for reprinting an older sale, including one made on another device. The
 * per-line unit price is derived from the line total and quantity — the history
 * endpoint carries the total, which is what the customer was charged, and a
 * receipt that showed a re-multiplied price could disagree with it by a rounding
 * step.
 */
export function receiptFromHistory(
  sale: RecentSale,
  context: { shopName: string; currency: string },
): Receipt {
  const lines: ReceiptLine[] = sale.items.map((item) => {
    const quantity = item.quantity ?? 1
    return {
      name: item.name ?? `#${item.product_id}`,
      quantity,
      price: quantity > 0 ? round2(item.total / quantity) : item.total,
      total: item.total,
      // Already snapshotted on the sale line server-side; the reprint was
      // simply throwing it away and printing a box the same as a bottle.
      unit: item.unit_name ?? null,
    }
  })

  const subtotal = round2(lines.reduce((sum, l) => sum + l.total, 0))

  return {
    no: String(sale.id),
    kind: sale.kind === 'debt' ? 'credit' : 'paid',
    shopName: context.shopName,
    sellerName: sale.seller_name ?? '—',
    occurredAt: sale.created_at ?? new Date().toISOString(),
    lines,
    subtotal,
    discount: sale.discount_amount,
    total: sale.total,
    paymentType: sale.payment_type,
    paid: sale.paid_amount,
    change: 0,
    owed: round2(Math.max(0, sale.total - sale.paid_amount)),
    clientName: sale.client_name,
    currency: context.currency,
  }
}

/** Paper width, per device — the printer belongs to the machine, not the shop. */
const PAPER_KEY = 'pos.paper'

export type Paper = '58mm' | '80mm'

export function getPaper(): Paper {
  return localStorage.getItem(PAPER_KEY) === '58mm' ? '58mm' : '80mm'
}

export function setPaper(paper: Paper): void {
  localStorage.setItem(PAPER_KEY, paper)
}
