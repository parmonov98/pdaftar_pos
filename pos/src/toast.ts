import { useEffect, useState } from 'react'

/**
 * Transient messages, from anywhere, without prop-drilling a callback.
 *
 * What counts as a toast and what does not is the whole point of this file:
 *
 *   TOAST — something that HAPPENED. "Sotuv yozildi", "#40 bekor qilindi",
 *   "3 ta amal yuborildi". It is news, it is over, and it should get out of
 *   the way on its own.
 *
 *   NOT a toast — something that IS. "Internet yo'q, shuning uchun bu tugma
 *   ishlamaydi", "Tarix serverdan o'qiladi". That explains a control the
 *   cashier is looking at right now, and it has to stay on screen next to
 *   that control for as long as it is true. Floated into a corner and faded
 *   out after six seconds, it becomes a button that does nothing for no
 *   reason anybody can see.
 */

export type ToastKind = 'ok' | 'err' | 'warn'

export type Toast = {
  id: number
  kind: ToastKind
  text: string
  /** Mid-exit. Kept in the list for the length of the slide-out. */
  leaving?: boolean
}

/** How long each kind sits there before it leaves by itself. */
const LIFETIME: Record<ToastKind, number> = {
  ok: 4000,
  warn: 8000,
  // Errors are not read in four seconds by someone with a customer in front
  // of them, and the cashier usually has to do something about this one.
  err: 10000,
}

/** Matches the slide-out in the stylesheet. */
const EXIT_MS = 200

/**
 * At most this many at once. A sync that reports on twenty operations would
 * otherwise cover the screen it is reporting about.
 */
const MAX = 3

let toasts: Toast[] = []
let nextId = 1
const listeners = new Set<(list: Toast[]) => void>()

function publish() {
  const snapshot = toasts
  listeners.forEach((listen) => listen(snapshot))
}

export function toast(kind: ToastKind, text: string): number {
  const id = nextId++

  // A repeat of the message already showing replaces it rather than stacking
  // a second identical card — pressing Sinxronlash twice is one piece of
  // news, not two.
  toasts = toasts.filter((t) => !(t.text === text && !t.leaving))
  toasts = [...toasts, { id, kind, text }].slice(-MAX)
  publish()

  // Bare setTimeout, not window.setTimeout: this store is plain module
  // state with no DOM in it, and keeping it that way is what lets the
  // lifetimes be tested against a fake clock instead of by watching.
  setTimeout(() => dismiss(id), LIFETIME[kind])

  return id
}

export function dismiss(id: number): void {
  const found = toasts.find((t) => t.id === id)
  if (!found || found.leaving) return

  toasts = toasts.map((t) => (t.id === id ? { ...t, leaving: true } : t))
  publish()

  setTimeout(() => {
    toasts = toasts.filter((t) => t.id !== id)
    publish()
  }, EXIT_MS)
}

/** Watch the stack. What the hook is built on, and how a test reads it. */
export function subscribe(listen: (list: Toast[]) => void): () => void {
  listeners.add(listen)
  return () => {
    listeners.delete(listen)
  }
}

/** The stack as it stands. */
export function currentToasts(): Toast[] {
  return toasts
}

export function useToasts(): Toast[] {
  const [list, setList] = useState<Toast[]>(toasts)

  useEffect(() => subscribe(setList), [])

  return list
}

/** Test seam. The store is module state, so it outlives a component. */
export function resetToasts(): void {
  toasts = []
  nextId = 1
  publish()
}
