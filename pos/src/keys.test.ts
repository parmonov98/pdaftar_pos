import { describe, expect, it } from 'vitest'
import { keyOwner } from './keys'
import type { View } from './screens/Drawer'

/**
 * Which screen a keypress belongs to.
 *
 * Every case here is a way the till stopped being usable without a mouse.
 * None of them threw, none showed an error, and all of them are invisible in
 * a screenshot — the sale screen's key handler was mounted for the whole app
 * and quietly ran its bindings on screens that were not showing.
 */
const press = (
  key: string,
  over: Partial<{ view: View; drawerOpen: boolean; dialogOpen: boolean; typing: boolean }> = {},
) =>
  keyOwner({
    key,
    view: 'sale',
    drawerOpen: false,
    dialogOpen: false,
    typing: false,
    ...over,
  })

describe('keyOwner', () => {
  it('gives the sale screen its own keys', () => {
    expect(press('Tab')).toBe('sale')
    expect(press('ArrowDown')).toBe('sale')
    expect(press('Delete')).toBe('sale')
    expect(press('F3')).toBe('sale')
    expect(press('F4')).toBe('sale')
  })

  /**
   * The shipped trap. Tarix is a list of a hundred sales and Tab did nothing
   * at all on it: the sale screen swallowed the key to switch between two
   * panes that were not rendered.
   */
  it('keeps the cart bindings off every other screen', () => {
    for (const key of ['Tab', 'ArrowDown', 'ArrowUp', 'Delete', 'Backspace', '+', '-']) {
      expect(press(key, { view: 'history' })).toBe('none')
      expect(press(key, { view: 'clients' })).toBe('none')
      expect(press(key, { view: 'queue' })).toBe('none')
    }
  })

  it('opens the menu and syncs from anywhere', () => {
    // The menu is the only route to five of the six screens. Mouse-only, it
    // made those five mouse-only too.
    for (const view of ['sale', 'history', 'products', 'devices'] as View[]) {
      expect(press('F2', { view })).toBe('global')
      expect(press('F9', { view })).toBe('global')
    }
  })

  it('lets Escape out of a screen the menu opened', () => {
    expect(press('Escape', { view: 'history' })).toBe('global')
    expect(press('Escape', { view: 'queue' })).toBe('global')
  })

  it('closes the menu before it leaves the screen', () => {
    expect(press('Escape', { view: 'history', drawerOpen: true })).toBe('global')
  })

  it('leaves Escape to a text field that has focus', () => {
    // In a search box Escape clears the box. Taking it to change screens
    // would make the clear gesture jump the cashier somewhere else.
    expect(press('Escape', { view: 'history', typing: true })).toBe('none')
  })

  it('hands the arrows to the menu while the menu is open', () => {
    expect(press('ArrowDown', { drawerOpen: true })).toBe('drawer')
    expect(press('Tab', { drawerOpen: true })).toBe('drawer')
    // …but not the keys that open and close it.
    expect(press('F2', { drawerOpen: true })).toBe('global')
  })

  it('gives an open dialog everything, including the global keys', () => {
    // The checkout binds its own Enter and Escape. A global key firing
    // through it would move the screen out from under a half-finished
    // payment.
    expect(press('Enter', { dialogOpen: true })).toBe('none')
    expect(press('Escape', { dialogOpen: true })).toBe('none')
    expect(press('F2', { dialogOpen: true })).toBe('none')
    expect(press('F9', { dialogOpen: true })).toBe('none')
  })

  it('does not claim keys nobody bound', () => {
    expect(press('q', { view: 'history' })).toBe('none')
    expect(press('F5', { view: 'history' })).toBe('none')
  })
})
