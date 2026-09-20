import { dismiss, useToasts } from '../toast'

/**
 * The toast stack, top-right, sliding in from the edge of the screen.
 *
 * Mounted once at the top of the app rather than per screen. These used to be
 * a block inside the sale panel: it pushed the basket down as it appeared and
 * let it snap back as it went, so the row a cashier was aiming at moved under
 * their finger. Out of the flow, nothing below it moves.
 *
 * Each one is a button, so it is dismissible with the keyboard as well as a
 * tap — on a till that is run without a mouse, a message that can only be
 * waited out is a message that sits over the screen.
 */
export function Toasts() {
  const toasts = useToasts()

  if (toasts.length === 0) return null

  return (
    <div className="toast-stack">
      {toasts.map((t) => (
        <button
          key={t.id}
          type="button"
          className={`toast ${t.kind} ${t.leaving ? 'leaving' : ''}`}
          onClick={() => dismiss(t.id)}
          // Errors interrupt a screen reader; the other two wait for a gap.
          role={t.kind === 'err' ? 'alert' : 'status'}
          aria-live={t.kind === 'err' ? 'assertive' : 'polite'}
        >
          <span className="toast-text">{t.text}</span>
          <span className="toast-x" aria-hidden>
            ✕
          </span>
        </button>
      ))}
    </div>
  )
}
