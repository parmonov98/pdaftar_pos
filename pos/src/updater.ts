import { registerSW } from 'virtual:pwa-register'
import { isPushing } from './sync'
import { toast } from './toast'

/**
 * Getting a new build onto a till that nobody ever closes.
 *
 * A kassa is opened at 8am and the tab stays up until closing. Before this,
 * a deploy reached it only when somebody reloaded — and because the service
 * worker serves the cached shell, an ordinary reload could still hand back
 * the old bundle. In practice a fix shipped in the morning was on the tills
 * that happened to be restarted and nowhere else, which is the worst of both
 * worlds: the same version number, two different behaviours, and bug reports
 * that cannot be reproduced.
 *
 * So: check for a new build on a timer, and when one is ready, reload — but
 * only at a moment where a reload costs nothing.
 *
 * **What a reload actually costs here is small, and knowing that is what
 * makes this safe.** Drafts live in Dexie and the outbox is written before
 * any packet leaves the machine, so a reload loses neither a basket nor a
 * sale; a push interrupted half way is re-sent later under the same
 * operation id, which the server already dedupes. What a reload DOES lose is
 * whatever is only on screen: a half-typed discount, an open checkout, the
 * cancel confirmation somebody is reading. That is the whole of the risk,
 * and it is exactly what `safeToReload` waits out.
 */

/** How often to ask whether a new build exists. */
const CHECK_EVERY_MS = 60_000

/** How often to re-test whether the till is idle enough to take it. */
const POLL_EVERY_MS = 2_000

/**
 * Quiet time before a reload. Long enough that it never lands between two
 * scans, short enough that the gap after one customer leaves is usually
 * sufficient.
 */
const QUIET_MS = 10_000

/**
 * If the till has been busy this long with an update in hand, stop waiting
 * quietly and say so. A rush that never lets up is exactly when a shop most
 * wants the fix, and a silent wait is indistinguishable from a broken
 * updater.
 */
const NAG_AFTER_MS = 10 * 60_000

export type TillActivity = {
  /** A modal is up — checkout, the cancel confirmation, a form. */
  dialogOpen: boolean
  /**
   * A field holds text that has not been submitted yet.
   *
   * Focus alone is NOT enough, and getting that wrong made this whole
   * mechanism a no-op: the sale screen autofocuses its search box, so from
   * the moment the till loads an input has focus and never gives it up. A
   * gate on focus therefore blocked every update forever, on every till,
   * which is the failure this file exists to prevent.
   *
   * An empty box that nobody has typed into for ten seconds is not work in
   * progress. A half-typed discount is.
   */
  typing: boolean
  /** The outbox is mid-flight. Safe to interrupt, but pointless to. */
  pushing: boolean
  /** Since the last key, tap or click. */
  msSinceInteraction: number
}

/**
 * Whether a service-worker event means a NEW BUILD is live, or is just this
 * page being adopted for the first time.
 *
 * With `skipWaiting` the new worker activates on its own and claims the open
 * pages, so the signal is `controllerchange` rather than a worker sitting in
 * `waiting`. But that same event fires on a first-ever visit, the moment the
 * freshly installed worker claims a page that loaded without one — and
 * reloading there would bounce every cashier once on the first load after
 * clearing site data, for no reason at all.
 *
 * The thing that tells them apart is whether this page was ALREADY under a
 * worker when it started: if it was, the controller changing means it was
 * replaced, which only happens when a new build shipped.
 */
export function countsAsNewBuild(signal: {
  /** Was there a controller at page load? */
  hadControllerAtStart: boolean
  /** A worker is parked in `waiting` — belt and braces, see below. */
  isWaiting: boolean
  /** The controller changed since load. */
  controllerChanged: boolean
}): boolean {
  // A waiting worker is unambiguous: something newer exists and has not been
  // applied. Kept even though skipWaiting should prevent it, because a
  // browser can still defer activation while another tab holds the old one.
  if (signal.isWaiting) return true

  return signal.controllerChanged && signal.hadControllerAtStart
}

/**
 * Whether now is a moment the cashier would not notice losing.
 *
 * Deliberately conservative about the screen and relaxed about the data:
 * the data is already durable, the screen is not.
 */
export function safeToReload(activity: TillActivity, quietMs: number = QUIET_MS): boolean {
  if (activity.dialogOpen) return false
  if (activity.typing) return false
  if (activity.pushing) return false

  return activity.msSinceInteraction >= quietMs
}

/** What the page currently looks like, for the rule above. */
function readActivity(lastInteraction: number): TillActivity {
  const active = document.activeElement
  const tag = active?.tagName

  // Only a field with something IN it counts — see the note on `typing`. A
  // <select> is excluded because it always has a value, so it would block
  // for as long as it holds focus; the quiet period covers it.
  const editable = tag === 'INPUT' || tag === 'TEXTAREA'
  const value = editable ? ((active as HTMLInputElement).value ?? '') : ''

  return {
    dialogOpen:
      document.querySelector('.overlay, [role="dialog"], [role="alertdialog"]') !== null ||
      document.querySelector('.drawer.on') !== null,
    typing: editable && value.trim() !== '',
    pushing: isPushing(),
    msSinceInteraction: Date.now() - lastInteraction,
  }
}

/**
 * Start watching for new builds.
 *
 * Called once, before React mounts. Registration is done here rather than by
 * the plugin's injected script because the point of this file is to own WHEN
 * the new worker takes over — `autoUpdate` would have skipped the wait and
 * swapped the assets under a running page, which is how a cashier ends up
 * on a half-old, half-new bundle until something forces a reload.
 */
export function watchForUpdates(): void {
  let lastInteraction = Date.now()
  let readySince: number | null = null
  let nagged = false
  let reloading = false

  const touch = () => {
    lastInteraction = Date.now()
  }

  for (const event of ['keydown', 'pointerdown', 'touchstart', 'wheel']) {
    window.addEventListener(event, touch, { passive: true, capture: true })
  }

  let registration: ServiceWorkerRegistration | undefined
  let controllerChanged = false

  /*
   * Whether this page started life under a worker.
   *
   * Read ONCE, before anything can claim it. On a first-ever visit there is
   * no controller, the newly installed worker claims the page seconds
   * later, and without this flag that would read as "a new build shipped"
   * and bounce the cashier on their first load.
   */
  const hadControllerAtStart = navigator.serviceWorker.controller !== null

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    controllerChanged = true
  })

  const markReady = () => {
    readySince ??= Date.now()
  }

  // Resolves to a function that activates the waiting worker and reloads.
  const updateSW = registerSW({
    immediate: true,

    onRegisteredSW(_url, reg) {
      if (!reg) return
      registration = reg

      // A build that finished installing before this callback attached is
      // already sitting in `waiting`, and its `updatefound` has been and
      // gone — so onNeedRefresh will never fire for it. That is not an edge
      // case: it is what happens to every till whose deploy lands while the
      // page is still loading, and without this they are the tills that stay
      // on the old build indefinitely.
      if (reg.waiting) markReady()

      // A till that is never navigated would otherwise never ask. The check
      // is one conditional request against an unchanged sw.js on a normal
      // day, which is cheaper than a shop running yesterday's code.
      setInterval(() => {
        // Nothing to check against with no connection, and a failed update()
        // rejects — unhandled, it fills the console of a till on shop wifi.
        if (navigator.onLine) void reg.update().catch(() => undefined)
      }, CHECK_EVERY_MS)
    },

    onNeedRefresh: markReady,
  })

  setInterval(() => {
    // The registration and the controller are the truth, not the callback.
    // Every way a new build can arrive — the event we caught, the one we
    // missed, a worker another tab installed, one that skipped the wait and
    // claimed us outright — looks the same here.
    if (
      countsAsNewBuild({
        hadControllerAtStart,
        isWaiting: registration?.waiting != null,
        controllerChanged,
      })
    ) {
      markReady()
    }

    if (readySince === null || reloading) return

    if (safeToReload(readActivity(lastInteraction))) {
      reloading = true

      // Two ways in, because both states are reachable: a worker that is
      // still waiting has to be told to go, and one that already claimed
      // this page just needs the page to pick up its assets.
      if (registration?.waiting) void updateSW(true)
      else window.location.reload()

      // Either way the cashier sees the till blink; the basket, the queue
      // and the open tabs all come back, because none of them live in the
      // page.
      return
    }

    if (!nagged && Date.now() - readySince > NAG_AFTER_MS) {
      nagged = true
      toast('warn', "Yangi versiya tayyor — kassa bo'shaganda o'zi yangilanadi.")
    }
  }, POLL_EVERY_MS)
}
