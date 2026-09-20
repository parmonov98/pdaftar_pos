import type { View } from './screens/Drawer'

/**
 * Who owns a keypress.
 *
 * The till has one window-level key handler and several screens, and the
 * question "does this key belong to me" was being answered inline, in the
 * middle of the handler, differently each time. It got the answer wrong in a
 * way nothing could see: the sale screen's handler stayed mounted on every
 * OTHER screen, so on Tarix the arrows walked a cart cursor that was not on
 * screen, Delete dropped lines out of a basket nobody was looking at, and Tab
 * was swallowed by a switch between two panes that were not rendered — which
 * left that screen with no working Tab at all, and no way in or out by
 * keyboard.
 *
 * Pulled out here as a pure function so the rule is one thing, stated once,
 * and a test can hold it still.
 */
export type KeyOwner =
  /** Works anywhere: the menu, sync, and the way back to the sale screen. */
  | 'global'
  /** The menu is open and modal; its own arrows and Tab apply. */
  | 'drawer'
  /** The cart and product panes. */
  | 'sale'
  /** Nobody — let the browser do whatever it does, which is usually right. */
  | 'none'

export function keyOwner(event: {
  key: string
  view: View
  drawerOpen: boolean
  /** A modal dialog is up. It has its own keys and they win outright. */
  dialogOpen: boolean
  /** Focus is in a text field, so letters and arrows belong to the field. */
  typing: boolean
}): KeyOwner {
  // A dialog is the whole world while it is open. Nothing below may reach
  // past it — the checkout has its own Enter, and stealing it would submit
  // the sale from under the cashier.
  if (event.dialogOpen) return 'none'

  if (event.key === 'F2' || event.key === 'F9') return 'global'

  if (event.key === 'Escape') {
    if (event.drawerOpen) return 'global'
    // Escape out of Tarix, Mijozlar, Navbat… back to selling. Not while
    // typing: there it clears the field, which is what Escape does in a text
    // box everywhere else.
    if (event.view !== 'sale' && !event.typing) return 'global'
  }

  if (event.drawerOpen) return 'drawer'

  // The heart of it. Everything the sale screen binds — Tab, the arrows,
  // + and −, Delete — is scoped to the sale screen actually being on screen.
  if (event.view !== 'sale') return 'none'

  return 'sale'
}
