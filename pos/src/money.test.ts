import { describe, expect, it } from 'vitest'
import { addLine, priceFor } from './drafts'
import { cartSubtotal, lineTotal, type CartLine } from './sales'
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
