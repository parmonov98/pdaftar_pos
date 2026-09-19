import { db, getMeta, setMeta, type DraftLine, type Product, type SaleDraft } from './db'

/**
 * Open sale tabs.
 *
 * Every mutation goes straight to IndexedDB rather than living in React state
 * and being saved later. A basket that exists only in memory is one refresh
 * away from gone, and the seller who loses it has to ask the customer to hand
 * everything back over the counter.
 */

const ACTIVE_KEY = 'active_draft_id'

/**
 * Tab labels restart each day: "15.08-#1", "15.08-#2". Same shape the mobile
 * Sotuv calculator already uses for its tabs, so a seller moving between the
 * two apps reads the same thing.
 */
function nextName(existing: SaleDraft[]): string {
  const today = new Date()
  const stamp = `${String(today.getDate()).padStart(2, '0')}.${String(today.getMonth() + 1).padStart(2, '0')}`

  const used = existing
    .map((d) => d.name.startsWith(`${stamp}-#`) ? Number(d.name.slice(stamp.length + 2)) : 0)
    .filter((n) => Number.isFinite(n))

  return `${stamp}-#${Math.max(0, ...used) + 1}`
}

export async function listDrafts(): Promise<SaleDraft[]> {
  return db.drafts.orderBy('createdAt').toArray()
}

export async function createDraft(currencyId: number): Promise<SaleDraft> {
  const existing = await listDrafts()

  const draft: SaleDraft = {
    id: crypto.randomUUID(),
    name: nextName(existing),
    // ISO string rather than a number: Dexie sorts it lexicographically and
    // that ordering is the tab order.
    createdAt: new Date().toISOString(),
    lines: [],
    clientId: null,
    clientName: null,
    discountValue: '',
    discountMode: 'percent',
    currencyId,
  }

  await db.drafts.put(draft)
  await setActiveDraftId(draft.id)
  return draft
}

export async function updateDraft(id: string, patch: Partial<SaleDraft>): Promise<void> {
  await db.drafts.update(id, patch)
}

/**
 * Close a tab and hand back whichever one should take focus.
 *
 * Falls to the neighbour on the left, the way tab strips everywhere behave —
 * jumping to the far end after a close loses the seller's place.
 */
export async function closeDraft(id: string): Promise<string | null> {
  const drafts = await listDrafts()
  const index = drafts.findIndex((d) => d.id === id)

  await db.drafts.delete(id)

  const remaining = drafts.filter((d) => d.id !== id)
  if (remaining.length === 0) return null

  const next = remaining[Math.max(0, index - 1)]
  await setActiveDraftId(next.id)
  return next.id
}

export function getActiveDraftId(): Promise<string | null> {
  return getMeta<string | null>(ACTIVE_KEY, null)
}

export function setActiveDraftId(id: string | null): Promise<void> {
  return setMeta(ACTIVE_KEY, id)
}

/**
 * Make sure exactly one tab is open and focused.
 *
 * Called on boot. An empty till with no tab has nowhere to scan into, so the
 * first one is opened silently rather than making the seller press "+" before
 * they can do anything.
 */
export async function ensureDraft(currencyId: number): Promise<string> {
  const drafts = await listDrafts()

  if (drafts.length === 0) {
    const created = await createDraft(currencyId)
    return created.id
  }

  const active = await getActiveDraftId()
  if (active !== null && drafts.some((d) => d.id === active)) return active

  await setActiveDraftId(drafts[0].id)
  return drafts[0].id
}

/** Add one unit of a product to a draft, merging with an existing line. */
/**
 * What one of `productUnitId` costs.
 *
 * Mirrors the server's order — the exact row, then the cash price for the
 * same unit, and only then the product's own column, which means the BASE
 * unit and nothing else. Pricing a box from it would be out by a factor of
 * twelve, so it deliberately does not scale.
 */
export function priceFor(product: Product, productUnitId: number | null): number | null {
  const unit = product.units?.find((u) => u.id === productUnitId)

  if (unit) {
    const rows = product.prices ?? []
    const exact = rows.find((p) => p.product_unit_id === unit.id && p.type === 'sale')
    if (exact) return exact.amount

    const isBase = unit.numerator === 1 && unit.denominator === 1
    if (!isBase) return null
  }

  return product.price ?? null
}

export function addLine(lines: DraftLine[], product: Product): DraftLine[] {
  const existing = lines.find((l) => l.productId === product.id)

  if (existing) {
    return lines.map((l) =>
      l.productId === product.id ? { ...l, quantity: round2(l.quantity + 1) } : l,
    )
  }

  // Scanning adds the base unit: a barcode is on a bottle, not on a box of
  // them. The cashier changes it on the line when they meant the box.
  //
  // Deliberately NOT "whichever unit came first". A product whose karobka was
  // stored before its dona would have every scan ring up a box: twelve off the
  // shelf and the wrong price, with nothing on screen saying so. Same trap the
  // server's baseUnit() had.
  const base =
    product.units?.find((u) => u.is_base) ??
    product.units?.find((u) => u.numerator === 1 && u.denominator === 1) ??
    null

  return [
    ...lines,
    {
      productId: product.id,
      name: product.name ?? '',
      quantity: 1,
      price: priceFor(product, base?.id ?? null) ?? product.price ?? 0,
      productUnitId: base?.id ?? null,
    },
  ]
}

function round2(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100
}
