import { describe, expect, it } from 'vitest'
import { addLine, priceFor } from './drafts'
import {
  cancelBlocker,
  cancelEffects,
  cartByCurrency,
  cartSubtotal,
  formatMoney,
  lineTotal,
  owedIn,
  subtotalsByCurrency,
  type CancellableSale,
  type CartLine,
} from './sales'
import type { Product, ProductUnitOption } from './db'

/**
 * The arithmetic between a shelf and a customer.
 *
 * Every case here is a bug that shipped. None of them threw, none showed an
 * error, and all of them were wrong by a factor of twelve or by the price of
 * a box — which is exactly why the till needs tests at all: the failures in
 * this file are invisible on screen and only turn up when someone counts the
 * money at closing.
 */

const DONA: ProductUnitOption = {
  id: 1,
  unit_id: 10,
  numerator: 1,
  denominator: 1,
  is_base: true,
  is_active: true,
}

const KAROBKA: ProductUnitOption = {
  id: 2,
  unit_id: 11,
  numerator: 12,
  denominator: 1,
  is_base: false,
  is_active: true,
}

function fanta(overrides: Partial<Product> = {}): Product {
  return {
    id: 100,
    shop_id: 1,
    name: 'Fanta 1L',
    code: null,
    barcode: null,
    unit_id: 10,
    currency_id: 1,
    price: 12000,
    quantity: 6,
    low_stock_threshold: null,
    image_url: null,
    deleted: false,
    updated_at: '2026-09-20T00:00:00Z',
    units: [DONA, KAROBKA],
    prices: [
      { product_unit_id: 1, currency_id: 1, amount: 12000, type: 'sale' },
      { product_unit_id: 2, currency_id: 1, amount: 130000, type: 'sale' },
    ],
    ...overrides,
  } as Product
}

describe('priceFor', () => {
  it('prices each unit from its own row', () => {
    expect(priceFor(fanta(), DONA.id)).toBe(12000)
    expect(priceFor(fanta(), KAROBKA.id)).toBe(130000)
  })

  it('refuses to price a larger unit from the base unit column', () => {
    // A box priced from products.price would ring up at 12 000 — one
    // twelfth of the box, and nothing on screen would look unusual.
    const unpriced = fanta({ prices: [{ product_unit_id: 1, currency_id: 1, amount: 12000, type: 'sale' }] })
    expect(priceFor(unpriced, KAROBKA.id)).toBeNull()
  })

  it('falls back to the legacy column for the base unit only', () => {
    const noRows = fanta({ prices: [] })
    expect(priceFor(noRows, DONA.id)).toBe(12000)
    expect(priceFor(noRows, KAROBKA.id)).toBeNull()
  })
})

describe('addLine', () => {
  it('scans in the base unit', () => {
    const [line] = addLine([], fanta())
    expect(line.productUnitId).toBe(DONA.id)
    expect(line.price).toBe(12000)
  })

  it('does not treat the first-stored unit as the base one', () => {
    // The shipped bug: units[0] was taken as the base. A product whose
    // karobka happened to be stored first had every scan ring up a box —
    // twelve off the shelf at the wrong price.
    const boxFirst = fanta({ units: [{ ...KAROBKA, is_base: false }, DONA] })
    const [line] = addLine([], boxFirst)
    expect(line.productUnitId).toBe(DONA.id)
  })

  it('has no unit rather than a wrong one when none is 1:1', () => {
    const odd = fanta({ units: [{ ...KAROBKA, is_base: false }] })
    const [line] = addLine([], odd)
    expect(line.productUnitId).toBeNull()
  })

  it('bumps the quantity instead of adding a second row', () => {
    const lines = addLine(addLine([], fanta()), fanta())
    expect(lines).toHaveLength(1)
    expect(lines[0].quantity).toBe(2)
  })
})

describe('cart totals', () => {
  const line = (over: Partial<CartLine> = {}): CartLine => ({
    product: fanta(),
    quantity: 3,
    price: 12000,
    productUnitId: DONA.id,
    currencyId: 1,
    ...over,
  })

  it('multiplies quantity by the line price', () => {
    expect(lineTotal(line())).toBe(36000)
  })

  it('sums fractional quantities without drift', () => {
    // Weighed goods: 0.1 kg three times must not land on 30000.000000004.
    const grams = line({ quantity: 0.1, price: 100000 })
    expect(cartSubtotal([grams, grams, grams])).toBe(30000)
  })
})

describe('owedIn', () => {
  it('keeps currencies apart, largest debt first', () => {
    // Merged into one number these read as 12,011 — a figure that is not
    // money, and the reason the balance is a map at all.
    expect(owedIn({ 1: 12000, 2: 11 })).toEqual([
      [1, 12000],
      [2, 11],
    ])
  })

  it('drops settled currencies', () => {
    expect(owedIn({ 1: 0, 2: 11 })).toEqual([[2, 11]])
  })

  it('keeps credit, which is a debt the other way round', () => {
    expect(owedIn({ 1: -8000 })).toEqual([[1, -8000]])
  })

  it('treats a missing map as nothing owed', () => {
    expect(owedIn(undefined)).toEqual([])
  })
})

/**
 * Undoing a sale.
 *
 * The arithmetic of a cancellation is not the arithmetic of a sale run
 * backwards: the goods return, the debt goes with them, and the money that
 * already changed hands does neither. Two of the lines below are cash the
 * cashier has to take back out of the drawer, and nothing on the row being
 * cancelled says so.
 */
describe('cancelBlocker', () => {
  const sale = (over: Partial<CancellableSale> = {}): CancellableSale => ({
    id: 7,
    total: 24000,
    paid_amount: 24000,
    is_cancelled: false,
    kind: 'income',
    ...over,
  })

  it('allows a live sale on a connected till', () => {
    expect(cancelBlocker(sale(), true)).toBeNull()
  })

  it('refuses offline, because the operation names a server id', () => {
    // A sale rung up offline has no server id until it syncs, the list it
    // would be picked from is server-backed, and the customer is being handed
    // money back now — not whenever the wifi returns.
    expect(cancelBlocker(sale(), false)).toContain("Internet yo'q")
  })

  it('refuses one that is already cancelled, online or not', () => {
    expect(cancelBlocker(sale({ is_cancelled: true }), true)).toContain('allaqachon')
    expect(cancelBlocker(sale({ is_cancelled: true }), false)).toContain('allaqachon')
  })
})

describe('cancelEffects', () => {
  const sale = (over: Partial<CancellableSale> = {}): CancellableSale => ({
    id: 7,
    total: 24000,
    paid_amount: 24000,
    is_cancelled: false,
    kind: 'income',
    ...over,
  })

  const joined = (s: CancellableSale) => cancelEffects(s, 'UZS').join(' | ')

  // Amounts are formatted with uz-UZ group separators, which are NOT ASCII
  // spaces. Building the expectation the same way the screen does keeps the
  // test about the sentence rather than about Intl's choice of whitespace.
  const money = (value: number) => `${formatMoney(value)} UZS`

  it('always returns the stock and keeps the row', () => {
    const text = joined(sale())
    expect(text).toContain('omborga qaytariladi')
    expect(text).toContain('bekor qilingan')
  })

  it('names the cash that has to come back out of the drawer', () => {
    expect(joined(sale())).toContain(`Kassadan mijozga ${money(24000)}`)
  })

  it('names the debt a nasiya sale takes with it', () => {
    const text = joined(sale({ paid_amount: 0, kind: 'debt' }))
    expect(text).toContain(`qarzidan ${money(24000)} o'chiriladi`)
    // Nothing was handed over, so nothing is handed back.
    expect(text).not.toContain('Kassadan')
  })

  it('splits a part-paid nasiya sale into the debt and the cash', () => {
    const text = joined(sale({ paid_amount: 10000, kind: 'debt' }))
    expect(text).toContain(`qarzidan ${money(14000)} o'chiriladi`)
    expect(text).toContain(`Kassadan mijozga ${money(10000)}`)
  })

  it('warns that a later repayment is left behind as credit', () => {
    // The one that costs the shop money quietly: cancelling drops the debt
    // but not the payment, so the balance goes negative and the customer is
    // owed cash nobody told the cashier about.
    const text = joined(sale({ paid_amount: 0, kind: 'debt', repaid_amount: 10000 }))
    expect(text).toContain('haqdorlik')
    expect(text).toContain(money(10000))
  })

  it('says nothing about a repayment when there was none', () => {
    expect(joined(sale({ repaid_amount: 0 }))).not.toContain('haqdorlik')
  })

  it('spells out every currency of a mixed basket', () => {
    // Cancelled whole — the customer walked in once — so the cashier has to
    // be told what comes back in each currency rather than one figure that
    // mixes them.
    const text = cancelEffects(
      sale({
        totals: [
          { currency_id: 1, total: 12000, paid_amount: 12000 },
          { currency_id: 2, total: 6, paid_amount: 0 },
        ],
      }),
      'UZS',
      (id) => (id === 2 ? 'USD' : 'UZS'),
    ).join(' | ')

    expect(text).toContain(`Kassadan mijozga ${formatMoney(12000)} UZS`)
    expect(text).toContain(`qarzidan ${formatMoney(6)} USD o'chiriladi`)
    // And never a combined figure.
    expect(text).not.toContain('12 006')
    expect(text).not.toContain('12006')
  })

  it('falls back to the sale own figures when there are no parts', () => {
    // Anything written before mixed baskets existed has no `totals`, and is
    // exactly the single-currency sale it looks like.
    expect(joined(sale())).toContain(`Kassadan mijozga ${money(24000)}`)
  })
})

/**
 * A basket in two currencies.
 *
 * The rule the whole till is built on: so'm and dollars are never added,
 * never compared, never averaged. A mixed basket therefore is not one sale
 * with one total — it is one sale per currency, and these check the shape
 * the screen and the receipt are built from.
 */
describe('cartByCurrency', () => {
  const at = (currencyId: number, price: number, quantity = 1): CartLine => ({
    product: fanta(),
    quantity,
    price,
    productUnitId: DONA.id,
    currencyId,
  })

  it('keeps one currency as a single group', () => {
    const groups = cartByCurrency([at(1, 12000), at(1, 4000)])
    expect([...groups.keys()]).toEqual([1])
    expect(groups.get(1)).toHaveLength(2)
  })

  it('splits a mixed basket', () => {
    const groups = cartByCurrency([at(1, 12000), at(2, 11), at(1, 4000)])
    expect([...groups.keys()]).toEqual([1, 2])
    expect(groups.get(1)).toHaveLength(2)
    expect(groups.get(2)).toHaveLength(1)
  })

  it('orders the groups so the panel does not reshuffle as lines are added', () => {
    expect([...cartByCurrency([at(2, 11), at(1, 12000)]).keys()]).toEqual([1, 2])
  })

  it('totals each currency on its own', () => {
    const totals = subtotalsByCurrency([at(1, 12000, 2), at(2, 11), at(1, 4000)])

    // 28 000 so'm and 11 dollars. There is deliberately no third number:
    // 28 011 would be the bug this shape exists to make impossible.
    expect(totals.get(1)).toBe(28000)
    expect(totals.get(2)).toBe(11)
    expect(totals.size).toBe(2)
  })

  it('does not invent a currency for an empty basket', () => {
    expect(subtotalsByCurrency([]).size).toBe(0)
  })
})
