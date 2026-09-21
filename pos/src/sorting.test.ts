import { describe, expect, it } from 'vitest'
import { ariaSort, compareCells, nextSort, sortRows, type SortState } from './sorting'

/**
 * Column sorting.
 *
 * Every case here is a way a sorted list lies quietly: prices ordered as
 * text, "no price" treated as free, rows swapping places between renders.
 * None of them throw and all of them look like a working sort.
 */

type Col = 'name' | 'qty' | 'price'

type Row = { name: string; qty: number | null; price: number | null }

const rows: Row[] = [
  { name: 'Coca-Cola 1.5L', qty: 24, price: 12000 },
  { name: 'Non (patir)', qty: 9, price: 4000 },
  { name: "Nuppy gold banan", qty: null, price: 70000 },
  { name: 'Choy', qty: 3, price: null },
]

const cell = (row: Row, key: Col) => row[key]
const names = (list: Row[]) => list.map((r) => r.name)

describe('nextSort', () => {
  it('cycles ascending, descending, off', () => {
    let state: SortState<Col> = null

    state = nextSort(state, 'price')
    expect(state).toEqual({ key: 'price', dir: 'asc' })

    state = nextSort(state, 'price')
    expect(state).toEqual({ key: 'price', dir: 'desc' })

    // Off matters: the cart is in scan order and the history is newest
    // first, and a sort throws that away. Without a third click there is no
    // way back to it.
    state = nextSort(state, 'price')
    expect(state).toBeNull()
  })

  it('starts a different column at ascending', () => {
    // Inheriting 'desc' from the previous column reads as the click having
    // done nothing much.
    const state = nextSort<Col>({ key: 'price', dir: 'desc' }, 'name')
    expect(state).toEqual({ key: 'name', dir: 'asc' })
  })
})

describe('sortRows', () => {
  it('leaves the list alone when nothing is sorted', () => {
    expect(sortRows(rows, null, cell)).toBe(rows)
  })

  it('sorts numbers numerically, not as text', () => {
    // As strings this is 12000 < 4000 < 70000, which is the classic silent
    // wrong answer.
    expect(names(sortRows(rows, { key: 'price', dir: 'asc' }, cell))).toEqual([
      'Non (patir)',
      'Coca-Cola 1.5L',
      'Nuppy gold banan',
      'Choy', // no price — last
    ])
  })

  it('keeps missing values last in BOTH directions', () => {
    // "No price" is not cheaper than 4,000 and not dearer than 70,000. A
    // product with nothing in the column being sorted belongs at the end.
    const asc = names(sortRows(rows, { key: 'price', dir: 'asc' }, cell))
    const desc = names(sortRows(rows, { key: 'price', dir: 'desc' }, cell))

    expect(asc.at(-1)).toBe('Choy')
    expect(desc.at(-1)).toBe('Choy')
  })

  it('puts never-inventoried stock last rather than treating it as zero', () => {
    // quantity null means never counted, not out of stock — sorted as 0 it
    // would head up "lowest stock first" and send somebody to re-count a
    // shelf that was never tracked.
    const asc = names(sortRows(rows, { key: 'qty', dir: 'asc' }, cell))
    expect(asc).toEqual(['Choy', 'Non (patir)', 'Coca-Cola 1.5L', 'Nuppy gold banan'])
  })

  it('sorts text by Uzbek collation', () => {
    const list = [{ name: "O'rik", qty: 1, price: 1 }, { name: 'Olma', qty: 1, price: 1 }]
    expect(names(sortRows(list, { key: 'name', dir: 'asc' }, cell))).toEqual(['Olma', "O'rik"])
  })

  it('is stable, so equal rows do not shuffle', () => {
    const same: Row[] = [
      { name: 'B', qty: 1, price: 5000 },
      { name: 'A', qty: 1, price: 5000 },
      { name: 'C', qty: 1, price: 5000 },
    ]
    // Three products at the same price must keep the order they had, or the
    // list reorders itself under the cashier's finger on every render.
    expect(names(sortRows(same, { key: 'price', dir: 'asc' }, cell))).toEqual(['B', 'A', 'C'])
    expect(names(sortRows(same, { key: 'price', dir: 'desc' }, cell))).toEqual(['B', 'A', 'C'])
  })

  it('does not mutate the list it was given', () => {
    // These arrays are React state, and a draft's line order is persisted.
    const before = [...rows]
    sortRows(rows, { key: 'price', dir: 'desc' }, cell)
    expect(rows).toEqual(before)
  })
})

describe('compareCells', () => {
  it('orders numbers, not their digits', () => {
    expect(compareCells(9000, 12000)).toBeLessThan(0)
  })

  it('treats empty string as missing', () => {
    expect(compareCells('', 'Olma')).toBeGreaterThan(0)
  })
})

describe('ariaSort', () => {
  it('announces only the column actually sorted', () => {
    const state: SortState<Col> = { key: 'price', dir: 'desc' }
    expect(ariaSort(state, 'price')).toBe('descending')
    expect(ariaSort(state, 'name')).toBe('none')
    expect(ariaSort(null, 'price')).toBe('none')
  })
})
