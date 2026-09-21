import { describe, expect, it } from 'vitest'
import { countsAsNewBuild, safeToReload, type TillActivity } from './updater'

/**
 * When a new build is allowed to take over.
 *
 * Both ways of getting this wrong are bad and neither shows up in a
 * screenshot: too eager and the page reloads out from under a cashier
 * halfway through a payment; too shy and the till runs last week's code all
 * week while the deploy is reported as done.
 */
const till = (over: Partial<TillActivity> = {}): TillActivity => ({
  dialogOpen: false,
  typing: false,
  pushing: false,
  msSinceInteraction: 60_000,
  ...over,
})

describe('safeToReload', () => {
  it('takes an idle till', () => {
    expect(safeToReload(till())).toBe(true)
  })

  it('waits while a dialog is open', () => {
    // The checkout, the cancel confirmation, a product form. Everything the
    // cashier has typed into it exists only on screen.
    expect(safeToReload(till({ dialogOpen: true }))).toBe(false)
  })

  it('waits while somebody is part way through typing', () => {
    expect(safeToReload(till({ typing: true }))).toBe(false)
  })

  it('does not count the autofocused, empty search box as typing', () => {
    // The shipped bug this rule was born with: the sale screen autofocuses
    // its search box, so an input has focus from the moment the till loads
    // and never gives it up. Gated on focus, no till would ever have
    // updated — the mechanism would have looked fine and done nothing.
    // `typing` is "a field holds unsubmitted text", which an empty box does
    // not, so an idle till is still reloadable.
    expect(safeToReload(till({ typing: false }))).toBe(true)
  })

  it('waits out a push', () => {
    // Survivable — the outbox is written before the request and the
    // operation id dedupes the resend — but there is no reason to choose it.
    expect(safeToReload(till({ pushing: true }))).toBe(false)
  })

  it('waits for the scanning to stop', () => {
    // Mid-basket: no dialog, no field focused, but a barcode scanner firing
    // keystrokes every second or two is not an idle till.
    expect(safeToReload(till({ msSinceInteraction: 1_200 }))).toBe(false)
    expect(safeToReload(till({ msSinceInteraction: 9_999 }))).toBe(false)
  })

  it('goes as soon as the quiet period is up', () => {
    expect(safeToReload(till({ msSinceInteraction: 10_000 }))).toBe(true)
  })

  it('needs every condition, not just the quiet time', () => {
    expect(safeToReload(till({ msSinceInteraction: 600_000, dialogOpen: true }))).toBe(false)
    expect(safeToReload(till({ msSinceInteraction: 600_000, typing: true }))).toBe(false)
    expect(safeToReload(till({ msSinceInteraction: 600_000, pushing: true }))).toBe(false)
  })

  it('honours a caller-supplied quiet period', () => {
    expect(safeToReload(till({ msSinceInteraction: 3_000 }), 2_000)).toBe(true)
    expect(safeToReload(till({ msSinceInteraction: 3_000 }), 5_000)).toBe(false)
  })
})

/**
 * Telling a new build apart from this page simply being adopted.
 *
 * The worker now skips the wait and claims open pages outright, so the
 * signal is the controller changing — and that same event fires on a
 * first-ever visit, where reloading would bounce the cashier for nothing.
 */
describe('countsAsNewBuild', () => {
  const signal = (over: Partial<Parameters<typeof countsAsNewBuild>[0]> = {}) =>
    countsAsNewBuild({
      hadControllerAtStart: true,
      isWaiting: false,
      controllerChanged: false,
      ...over,
    })

  it('says no while nothing has happened', () => {
    expect(signal()).toBe(false)
  })

  it('takes a worker parked in waiting', () => {
    // skipWaiting should prevent this, but a browser can still defer
    // activation while another tab holds the old worker.
    expect(signal({ isWaiting: true })).toBe(true)
  })

  it('takes the controller being replaced', () => {
    expect(signal({ controllerChanged: true })).toBe(true)
  })

  it('does NOT count the first worker claiming a fresh page', () => {
    // A first-ever visit loads with no controller and is claimed seconds
    // later. Treated as an update, every cashier gets bounced once on the
    // first load after clearing site data — for nothing.
    expect(signal({ hadControllerAtStart: false, controllerChanged: true })).toBe(false)
  })

  it('still takes a waiting worker on a page that started uncontrolled', () => {
    // Something newer genuinely exists and has not been applied; how this
    // page started is beside the point.
    expect(signal({ hadControllerAtStart: false, isWaiting: true })).toBe(true)
  })
})
