/**
 * Sorting a list by one of its columns.
 *
 * One implementation for all five lists, because the parts that are easy to
 * get wrong are the same everywhere and are all invisible when they are
 * wrong: a price column sorted as text puts 9,000 above 12,000, a missing
 * value sorted as zero puts every unpriced product at the top of "cheapest
 * first", and an unstable sort reshuffles equal rows every time the list
 * re-renders under the cashier's finger.
 */

export type SortDir = 'asc' | 'desc'

/** null means the list's own natural order — see `nextSort`. */
export type SortState<K extends string> = { key: K; dir: SortDir } | null

/**
 * What clicking a header does next: ascending, descending, then OFF.
 *
 * The third state is not a nicety. The cart is in the order things were
 * scanned and the history is newest-first, and both of those orders carry
 * meaning that a sort destroys; without a way back the cashier would have to
 * guess which column happened to be the original one. Clicking a DIFFERENT
 * column always starts that column at ascending rather than inheriting the
 * last direction, which otherwise reads as the list ignoring the click.
 */
export function nextSort<K extends string>(current: SortState<K>, key: K): SortState<K> {
  if (current?.key !== key) return { key, dir: 'asc' }
  if (current.dir === 'asc') return { key, dir: 'desc' }
  return null
}

/**
 * Compare two cell values.
 *
 * Numbers numerically, text by Uzbek collation, and absent values last in
 * BOTH directions — "no price" is not cheaper than 1,000 som and not dearer
 * than a million either; it is simply not a price, and a row with nothing in
 * the column the cashier is sorting by belongs at the end of the list.
 */
export function compareCells(a: unknown, b: unknown): number {
  const aMissing = a === null || a === undefined || a === ''
  const bMissing = b === null || b === undefined || b === ''

  if (aMissing && bMissing) return 0
  // Returned as a fixed sign rather than through the direction flip below,
  // which is what keeps them at the bottom either way.
  if (aMissing) return 1
  if (bMissing) return -1

  if (typeof a === 'number' && typeof b === 'number') return a - b
  if (typeof a === 'boolean' && typeof b === 'boolean') return Number(a) - Number(b)

  return String(a).localeCompare(String(b), 'uz', { numeric: true, sensitivity: 'base' })
}

/**
 * A sorted copy. The input is never mutated — these lists are React state
 * and a draft's line order is persisted.
 *
 * Stable, and deliberately so: three products at the same price keep the
 * order they already had rather than swapping places on every render.
 * `Array.prototype.sort` is specified stable, and the index tiebreak below
 * makes that explicit for the missing-value cases, which sort by a constant.
 */
export function sortRows<T, K extends string>(
  rows: T[],
  state: SortState<K>,
  cell: (row: T, key: K) => unknown,
): T[] {
  if (state === null) return rows

  const dir = state.dir === 'asc' ? 1 : -1

  return rows
    .map((row, index) => ({ row, index }))
    .sort((a, b) => {
      const av = cell(a.row, state.key)
      const bv = cell(b.row, state.key)

      const aMissing = av === null || av === undefined || av === ''
      const bMissing = bv === null || bv === undefined || bv === ''

      // The flip applies to the comparison, not to "has a value at all".
      if (aMissing !== bMissing) return aMissing ? 1 : -1

      const result = compareCells(av, bv)
      return result === 0 ? a.index - b.index : result * dir
    })
    .map((wrapped) => wrapped.row)
}

/** What a column header should announce to a screen reader. */
export function ariaSort<K extends string>(
  state: SortState<K>,
  key: K,
): 'ascending' | 'descending' | 'none' {
  if (state?.key !== key) return 'none'
  return state.dir === 'asc' ? 'ascending' : 'descending'
}
