import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { currentToasts, dismiss, resetToasts, subscribe, toast } from './toast'

/**
 * The toast store.
 *
 * Module state driven by timers — the shape that either leaves a card on the
 * screen forever or takes it away before anybody read it. Both failures look
 * like nothing at all in a screenshot.
 */
const texts = () => currentToasts().map((t) => t.text)

beforeEach(() => {
  vi.useFakeTimers()
  resetToasts()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('toast', () => {
  it('shows what it was given', () => {
    toast('ok', 'Sotuv yozildi')
    expect(texts()).toEqual(['Sotuv yozildi'])
    expect(currentToasts()[0].kind).toBe('ok')
  })

  it('leaves on its own, after sliding out', () => {
    toast('ok', 'Sotuv yozildi')

    vi.advanceTimersByTime(4000)
    // Still in the list, but on its way out — the row has to survive the
    // slide-out or the card vanishes mid-animation.
    expect(currentToasts()[0].leaving).toBe(true)

    vi.advanceTimersByTime(200)
    expect(texts()).toEqual([])
  })

  it('keeps an error up more than twice as long as a success', () => {
    toast('ok', 'yozildi')
    toast('err', 'xatolik')

    // A cashier with a customer in front of them does not read a failure in
    // four seconds, and usually has to do something about this one.
    vi.advanceTimersByTime(4200)
    expect(texts()).toEqual(['xatolik'])

    vi.advanceTimersByTime(6200)
    expect(texts()).toEqual([])
  })

  it('replaces a repeat instead of stacking it', () => {
    // Pressing Sinxronlash twice is one piece of news, not two.
    toast('ok', '3 ta amal yuborildi')
    toast('ok', '3 ta amal yuborildi')

    expect(texts()).toEqual(['3 ta amal yuborildi'])
  })

  it('keeps only the last three', () => {
    for (const t of ['a', 'b', 'c', 'd']) toast('ok', t)

    // A sync reporting on twenty operations would otherwise cover the screen
    // it is reporting about.
    expect(texts()).toEqual(['b', 'c', 'd'])
  })

  it('dismisses on demand, and a second press is harmless', () => {
    const id = toast('warn', 'navbatda')

    dismiss(id)
    expect(currentToasts()[0].leaving).toBe(true)

    dismiss(id)
    vi.advanceTimersByTime(200)
    expect(texts()).toEqual([])
  })

  it('does not resurrect a dismissed card when its own timer fires', () => {
    const id = toast('ok', 'yozildi')
    dismiss(id)
    vi.advanceTimersByTime(10_000)

    expect(texts()).toEqual([])
  })

  it('ignores an id it has never seen', () => {
    expect(() => dismiss(9999)).not.toThrow()
  })

  it('tells subscribers when the stack changes', () => {
    const seen: string[][] = []
    const stop = subscribeTexts((list) => seen.push(list))

    toast('ok', 'birinchi')
    expect(seen.at(-1)).toEqual(['birinchi'])

    stop()
    toast('ok', 'ikkinchi')
    expect(seen.at(-1)).toEqual(['birinchi'])
  })
})

function subscribeTexts(fn: (texts: string[]) => void) {
  return subscribe((list) => fn(list.map((t) => t.text)))
}
