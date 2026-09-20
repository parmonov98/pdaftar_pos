import { useEffect, useRef } from 'react'
import type { MeResponse } from '../api'

export type View = 'sale' | 'history' | 'clients' | 'products' | 'queue' | 'devices'

const SECTIONS: Array<{ view: View; icon: string; label: string; hint: string }> = [
  { view: 'sale', icon: '🛒', label: 'Sotuv', hint: 'Kassa oynasi' },
  { view: 'history', icon: '🧾', label: 'Tarix', hint: "Do'kondagi barcha sotuvlar" },
  { view: 'clients', icon: '👥', label: 'Mijozlar', hint: 'Qarzdorlar va telefonlar' },
  { view: 'products', icon: '📦', label: 'Mahsulotlar', hint: 'Katalog va qoldiqlar' },
  { view: 'queue', icon: '↻', label: 'Navbat', hint: 'Yuborilmagan amallar' },
  { view: 'devices', icon: '🖥', label: 'Qurilmalar', hint: "Do'konda ochilgan POS'lar" },
]

/**
 * The left drawer.
 *
 * Everything that is not "ring up a sale" lives here rather than competing for
 * space with the basket. The sale screen stays a single-purpose surface — a
 * seller with a customer in front of them should never have to look past the
 * thing they are doing.
 *
 * `pending` rides on the Navbat row because an outbox nobody can see is an
 * outbox nobody trusts; if something is stuck, the badge is visible from every
 * screen without opening anything.
 *
 * Opened with F2 as well as the ☰ button. It is the only route to five of the
 * six screens, so a mouse-only menu made those five mouse-only — on a machine
 * whose whole selling flow is designed for a keyboard and often has no mouse
 * attached at all.
 */
export function Drawer({
  open,
  view,
  me,
  pending,
  onNavigate,
  onClose,
  onLogout,
}: {
  open: boolean
  view: View
  me: MeResponse
  pending: number
  onNavigate: (view: View) => void
  onClose: () => void
  onLogout: () => void
}) {
  const panel = useRef<HTMLElement | null>(null)
  const returnTo = useRef<HTMLElement | null>(null)

  // Opening puts the keyboard on the current screen's row, so the arrows
  // start from where you are; closing hands focus back to whatever had it,
  // rather than dropping it on <body> where the next Tab starts from the top.
  useEffect(() => {
    if (!open) {
      returnTo.current?.focus()
      returnTo.current = null
      return
    }

    returnTo.current = document.activeElement as HTMLElement | null
    const current = panel.current?.querySelector<HTMLButtonElement>('.drawer-item.on')
    ;(current ?? panel.current?.querySelector<HTMLButtonElement>('.drawer-item'))?.focus()
  }, [open])

  function onKeyDown(event: React.KeyboardEvent) {
    const items = [...(panel.current?.querySelectorAll<HTMLButtonElement>('button') ?? [])]
    if (items.length === 0) return

    const at = items.indexOf(document.activeElement as HTMLButtonElement)

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      const step = event.key === 'ArrowDown' ? 1 : -1
      // Wraps, because a menu of six items that stops dead at the end makes
      // the cashier count rows instead of holding the key down.
      items[(at + step + items.length) % items.length]?.focus()
      return
    }

    if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault()
      items[event.key === 'Home' ? 0 : items.length - 1]?.focus()
      return
    }

    // The drawer is modal — it has a scrim over the rest of the app — so Tab
    // stays inside it. Tabbing onto controls hidden behind the dimming is how
    // a keyboard user ends up typing into a screen they cannot see.
    if (event.key === 'Tab') {
      event.preventDefault()
      const step = event.shiftKey ? -1 : 1
      items[(at + step + items.length) % items.length]?.focus()
    }
  }

  return (
    <>
      {/* Click-away, and the dimming that tells you the drawer is modal. */}
      <div className={`drawer-scrim ${open ? 'on' : ''}`} onClick={onClose} />

      {/* `inert` while closed. The panel is only slid off-screen, not removed,
          so without this its six buttons stayed in the tab order: Shift+Tab
          from the sale screen put the cursor inside an invisible menu, and
          Enter navigated somewhere the cashier never asked to go. */}
      <aside
        ref={panel}
        className={`drawer ${open ? 'on' : ''}`}
        inert={!open}
        aria-hidden={!open}
        aria-label="Menyu"
        onKeyDown={onKeyDown}
      >
        <div className="drawer-head">
          <div className="drawer-shop">{me.shop.name}</div>
          <div className="drawer-user">
            {me.user.name ?? me.user.phone_number}
            {me.user.name && <span className="sub"> · {me.user.phone_number}</span>}
          </div>
        </div>

        <nav className="drawer-nav">
          {SECTIONS.map((section) => (
            <button
              key={section.view}
              className={`drawer-item ${view === section.view ? 'on' : ''}`}
              aria-current={view === section.view}
              onClick={() => onNavigate(section.view)}
            >
              <span className="ic" aria-hidden>
                {section.icon}
              </span>
              <span className="grow">
                <span className="lb">{section.label}</span>
                <span className="hn">{section.hint}</span>
              </span>
              {section.view === 'queue' && pending > 0 && <span className="badge">{pending}</span>}
            </button>
          ))}
        </nav>

        <div className="drawer-foot">
          <button className="ghost" style={{ width: '100%' }} onClick={onLogout}>
            Chiqish
          </button>
        </div>
      </aside>
    </>
  )
}
