import { ariaSort, nextSort, type SortState } from '../sorting'

/**
 * One clickable column heading.
 *
 * A real <button>, so it is reachable by Tab and fires on Enter and Space
 * without any of that being written here — which matters on a till that is
 * run without a mouse.
 *
 * The arrow is drawn for the sorted column only. A permanent pair of faint
 * arrows on every heading is the usual approach and it makes four headings
 * look like four active controls; here the plain ones stay plain and the one
 * doing the work is the one that is marked.
 */
export function SortHeader<K extends string>({
  label,
  column,
  state,
  onChange,
  align = 'left',
  title,
}: {
  label: string
  column: K
  state: SortState<K>
  onChange: (next: SortState<K>) => void
  align?: 'left' | 'center' | 'right'
  title?: string
}) {
  const active = state?.key === column

  return (
    <button
      type="button"
      className={`sort-th ${align} ${active ? 'on' : ''}`}
      aria-sort={ariaSort(state, column)}
      // The heading says what it does, not just what it is. Without this a
      // screen reader hears "Narx, button" and nothing about sorting.
      aria-label={`${label} bo'yicha saralash`}
      title={title}
      onClick={() => onChange(nextSort(state, column))}
    >
      <span>{label}</span>
      <span className="sort-arrow" aria-hidden>
        {active ? (state.dir === 'asc' ? '▲' : '▼') : ''}
      </span>
    </button>
  )
}
