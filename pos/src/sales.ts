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

/**
 * The shop's house account for anonymous cash sales, created server-side by
 * PosWalkInClientResolver. Filtered out of the client picker: it is plumbing,
 * not a customer anyone would choose by name.
 */
export const WALK_IN_NAME = 'Naqd xaridor'

/**
 * The currencies a client actually owes something in, largest debt first.
 *
 * Zeroes are dropped: a currency they have settled is not worth a badge, and
 * showing "0 qarz" beside a name reads as a warning about nothing.
 */
export function owedIn(balances?: Record<number, number>): Array<[number, number]> {
  return Object.entries(balances ?? {})
    .map(([id, amount]) => [Number(id), amount] as [number, number])
    .filter(([, amount]) => amount !== 0)
    .sort((a, b) => b[1] - a[1])
}

export type CartLine = {
  product: Product
  quantity: number
  /** Cashier may override the catalogue price; the shop opted into that. */
  price: number
  /** Which unit is being sold. Null = the product's base unit. */
  productUnitId?: number | null
  /** Which currency this line is priced in. Resolved, never null here. */
  currencyId: number
}

/**
 * The basket split by currency, each with its own subtotal.
 *
 * A basket is no longer one number. Two lines in two currencies have two
 * totals and there is no third number that means anything — adding eleven
 * dollars to twelve thousand so'm is the mistake this whole codebase keeps
 * refusing to make, and it would be made here, on the screen the cashier
 * reads out loud to the customer.
 *
 * Ordered by currency id so the panel does not reshuffle as lines are added.
 *
 * @returns Map keyed by currency id
 */
export function cartByCurrency(lines: CartLine[]): Map<number, CartLine[]> {
  const groups = new Map<number, CartLine[]>()

  for (const line of lines) {
    const existing = groups.get(line.currencyId)
    if (existing) existing.push(line)
    else groups.set(line.currencyId, [line])
  }

  return new Map([...groups.entries()].sort((a, b) => a[0] - b[0]))
}

/** Subtotal per currency, in the same order. */
export function subtotalsByCurrency(lines: CartLine[]): Map<number, number> {
  const totals = new Map<number, number>()

  for (const [currencyId, group] of cartByCurrency(lines)) {
    totals.set(currencyId, cartSubtotal(group))
  }

  return totals
}

export type Payment = {
  /** null = nasiya (nothing handed over). */
  paymentType: 'cash' | 'card' | 'terminal' | 'bank_account' | null
  /**
   * What was handed over, PER CURRENCY, keyed by currency id.
   *
   * A customer buying a dollar-priced phone and a so'm-priced loaf pays two
   * amounts in two notes. One number could only be recorded against one
   * currency, which would mark the other half paid or unpaid at random.
   */
  paid: Record<number, number>
  /** Discount, per currency. A discount is money, so it has a currency too. */
  discounts: Record<number, number>
  clientId: number | null
  note: string
}

/** One currency's worth of a basket, as it went to the server. */
export type SalePart = {
  currencyId: number
  clientOperationId: string
  /** Outbox row id, so the printable receipt can be stored against the sale. */
  seq: number
  subtotal: number
  discount: number
  total: number
  paid: number
  change: number
  /** false when this part is sitting in the outbox waiting for a connection. */
  synced: boolean
  serverData: Record<string, unknown> | null
  error: string | null
}

export type SaleOutcome = {
  /**
   * One per currency in the basket, in currency order.
   *
   * A single-currency basket — which is nearly all of them — has exactly one
   * part and behaves as it always did.
   */
  parts: SalePart[]
  /** True only when every part reached the server. */
  synced: boolean
  /** The first thing that went wrong, if anything did. */
  error: string | null
}

export function lineTotal(line: CartLine): number {
  return round2(line.quantity * line.price)
}

export function cartSubtotal(lines: CartLine[]): number {
  return round2(lines.reduce((sum, line) => sum + lineTotal(line), 0))
}

/**
 * Ring up the basket.
 *
 * **One sale per currency.** A mixed basket is submitted as several
 * `sale.create` operations in the same outbox batch, one for each currency,
 * rather than as a single sale carrying a total that is not money. That
 * keeps every invariant the server already has: a sale has one currency, a
 * debt is in one currency, and a client's balance is a map of the two — all
 * of which would have had to be redesigned to make one row hold both, for a
 * basket the customer still experiences as one visit and one receipt.
 *
 * Sent in one batch and in currency order, so either the whole basket
 * reaches the server or it waits in the outbox together.
 */
export async function submitSale(
  lines: CartLine[],
  payment: Payment,
  fallbackCurrencyId: number,
): Promise<SaleOutcome> {
  if (lines.length === 0) throw new Error('Savatcha bo\'sh')

  const groups = cartByCurrency(
    lines.map((line) => ({ ...line, currencyId: line.currencyId || fallbackCurrencyId })),
  )

  const planned: Array<{
    currencyId: number
    lines: CartLine[]
    subtotal: number
    discount: number
    total: number
    paid: number
  }> = []

  for (const [currencyId, group] of groups) {
    const subtotal = cartSubtotal(group)
    const discount = round2(Math.min(payment.discounts[currencyId] ?? 0, subtotal))
    const total = round2(subtotal - discount)
    const paid = round2(payment.paid[currencyId] ?? 0)

    // Zero-total halves are dropped rather than sent: a currency whose whole
    // value was discounted away is not a sale, and the server refuses a
    // basket that totals nothing anyway.
    if (total <= 0 && paid <= 0) continue

    // Refused here rather than at the server so the cashier finds out while
    // the customer is still standing there, not at sync time hours later.
    if (paid + 0.000001 < total && payment.clientId === null) {
      throw new Error('Nasiya sotuv uchun mijoz tanlang')
    }

    planned.push({ currencyId, lines: group, subtotal, discount, total, paid })
  }

  if (planned.length === 0) throw new Error('Savdo summasi 0 dan katta bo\'lishi kerak')

  /*
   * What ties the halves together.
   *
   * Minted once, here, at the cashier's action — like the operation ids
   * beside it — so a retry of a half-sent basket re-uses the same group
   * rather than splitting one visit into two. Only for a basket that
   * actually spans currencies: a single-currency sale has no group, which
   * keeps its payload byte-identical to what the server has always been
   * sent.
   */
  const groupId = planned.length > 1 ? crypto.randomUUID() : null

  const parts: SalePart[] = []

  for (const plan of planned) {
    const payload = {
      currency_id: plan.currencyId,
      ...(groupId === null ? {} : { sale_group_id: groupId }),
      client_id: payment.clientId,
      payment_type: plan.paid > 0 ? payment.paymentType : null,
      paid_amount: plan.paid > 0 ? plan.paid : null,
      discount_amount: plan.discount,
      note: payment.note || null,
      items: plan.lines.map((line) => ({
        product_id: line.product.id,
        // Without this the server prices the base unit and takes one off the
        // shelf instead of twelve.
        product_unit_id: line.productUnitId ?? null,
        quantity: line.quantity,
        price: line.price,
      })),
    }

    const label = `Sotuv · ${plan.lines.length} ta · ${formatMoney(plan.total)}`
    const item = await enqueue('sale.create', payload, label)

    parts.push({
      currencyId: plan.currencyId,
      clientOperationId: item.client_operation_id,
      seq: item.seq!,
      subtotal: plan.subtotal,
      discount: plan.discount,
      total: plan.total,
      paid: plan.paid,
      change: plan.paid > plan.total ? round2(plan.paid - plan.total) : 0,
      synced: false,
      serverData: null,
      error: null,
    })
  }

  await decrementLocalStock(lines)

  // One push for the whole basket — the parts are already queued, so a
  // failure here leaves them all waiting together rather than half sent.
  let pushError: string | null = null
  try {
    await pushOutbox()
  } catch (e) {
    // Offline, or the server is down. Entirely expected — the sale is safe
    // in the outbox and nothing here needs to be undone.
    pushError = e instanceof Error ? e.message : String(e)
  }

  for (const part of parts) {
    const stored = await db.outbox.get(part.seq)
    part.synced = stored?.status === 'sent'
    part.serverData = stored?.result ?? null
    if (!part.synced) part.error = stored?.error ?? pushError
  }

  return {
    parts,
    synced: parts.every((p) => p.synced),
    error: parts.find((p) => p.error)?.error ?? null,
  }
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

      // In BASE units, the same as the server. Taking one off for a box of
      // twelve would leave this till showing a shelf that does not exist
      // until the next sync corrected it — and quietly overselling until it
      // did.
      const unit = current.units?.find((u) => u.id === line.productUnitId)
      const base = unit ? (line.quantity * unit.numerator) / unit.denominator : line.quantity

      await db.products.update(line.product.id, {
        quantity: round2(current.quantity - base),
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

// ─── Cancelling a sale ───

/**
 * A history row, as far as cancelling cares about it.
 *
 * Structural rather than the whole `RecentSale`, so the rules below can be
 * tested without a server payload — and so sales.ts does not depend on api.ts
 * in the direction that would make the cycle.
 */
export type CancellableSale = {
  id: number
  total: number
  paid_amount: number
  repaid_amount?: number
  is_cancelled: boolean
  kind?: 'income' | 'debt'
  /**
   * One entry per currency in the basket.
   *
   * Absent for anything that predates mixed baskets, which is treated as
   * the single-currency sale it is.
   */
  totals?: Array<{ currency_id: number | null; total: number; paid_amount: number }>
}

/**
 * Why this sale cannot be cancelled right now, or null when it can.
 *
 * **Cancelling is online-only, and that is a deliberate exception to the
 * outbox-first rule the rest of the till follows.** Four reasons, in order of
 * how badly each one bites:
 *
 *  1. `sale.cancel` names a SERVER sale id. A sale rung up offline has no such
 *     id until it syncs — nothing resolves an outbox row into one — so an
 *     offline cancel of the sale a cashier most wants to undo (the one they
 *     just made) could not be expressed at all.
 *  2. Tarix itself is server-backed. Offline the screen shows an error instead
 *     of rows, so there is no row to press this on in the first place.
 *  3. The customer is being handed money back NOW. A cancel that sits in the
 *     queue until the connection returns means the other tills — and the
 *     owner's phone — go on showing the debt as owed and the goods as sold,
 *     for as long as that takes. This is the same trade DebtPayment refuses.
 *  4. The decision itself may be stale: another till can have taken a
 *     repayment against this sale since this screen loaded.
 *
 * What the ledger does NOT object to is the ordering — reversal is a deletion
 * of the sale's movements and deltas commute, so an out-of-order cancel lands
 * on the same balance. The reasons above are about the id and the people, not
 * the arithmetic.
 */
export function cancelBlocker(sale: CancellableSale, online: boolean): string | null {
  if (sale.is_cancelled) return 'Bu sotuv allaqachon bekor qilingan.'

  if (!online) {
    return (
      "Internet yo'q. Bekor qilish uchun ulanish kerak — aks holda tovar boshqa " +
      'kassalarda sotilgan, qarz esa to\'lanmagan bo\'lib turaveradi.'
    )
  }

  return null
}

/**
 * Everything that changes when this is cancelled, in plain words.
 *
 * Spelled out because two of them cost the cashier money out of the drawer,
 * and neither is visible on the row being cancelled: the cash already taken
 * has to be handed back, and a repayment made against a nasiya sale turns
 * into credit the shop owes.
 */
export function cancelEffects(
  sale: CancellableSale,
  currency: string,
  /** Currency code per id, for a basket that spans more than one. */
  codeOf: (currencyId: number | null) => string = () => currency,
): string[] {
  const effects = ['Mahsulotlar omborga qaytariladi.']

  /*
   * Every currency in the basket, each spelled out on its own.
   *
   * A basket split across currencies is several sales on the server and is
   * cancelled whole — so the cashier has to be told what comes back in each
   * currency, not one figure that mixes them.
   */
  const parts =
    sale.totals && sale.totals.length > 0
      ? sale.totals
      : [{ currency_id: null, total: sale.total, paid_amount: sale.paid_amount }]

  for (const part of parts) {
    const code = sale.totals && sale.totals.length > 1 ? codeOf(part.currency_id) : currency
    const money = (value: number) => `${formatMoney(value)} ${code}`.trim()

    const owed = round2(part.total - part.paid_amount)
    if (owed > 0) effects.push(`Mijoz qarzidan ${money(owed)} o'chiriladi.`)

    if (part.paid_amount > 0) {
      effects.push(`Kassadan mijozga ${money(part.paid_amount)} qaytarish kerak.`)
    }
  }

  const money = (value: number) => `${formatMoney(value)} ${currency}`.trim()

  // Repayments taken AFTER the sale are not undone by cancelling it — they
  // stay on the client as credit. The shop owes that money back, and the only
  // moment anybody is in a position to notice is right now.
  const repaid = round2(sale.repaid_amount ?? 0)
  if (repaid > 0) {
    effects.push(
      `Mijoz keyin to'lagan ${money(repaid)} haqdorlik bo'lib qoladi — qaytarish kerak.`,
    )
  }

  effects.push("Sotuv tarixda \"bekor qilingan\" bo'lib qoladi, o'chirilmaydi.")

  return effects
}

/**
 * Put the cancel in the outbox and try to send it.
 *
 * Through the outbox even though the button is online-only: it is the one
 * write path, the operation id is minted at the cashier's press so a retry
 * cannot cancel twice, and if the connection drops between the press and the
 * response the decision is not lost — it goes up with the next Sinxronlash,
 * still naming a sale id the server knows.
 */
export async function cancelSale(
  sale: CancellableSale,
  currency: string,
): Promise<{ synced: boolean; error: string | null }> {
  const label = `Bekor qilish · #${sale.id} · ${formatMoney(sale.total)} ${currency}`.trim()
  const item = await enqueue('sale.cancel', { sale_id: sale.id }, label)

  try {
    await pushOutbox()
    const stored = await db.outbox.get(item.seq!)

    return {
      synced: stored?.status === 'sent',
      error: stored?.status === 'sent' ? null : (stored?.error ?? null),
    }
  } catch (e) {
    return { synced: false, error: e instanceof Error ? e.message : String(e) }
  }
}
