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
  return (
    <>
      {/* Click-away, and the dimming that tells you the drawer is modal. */}
      <div className={`drawer-scrim ${open ? 'on' : ''}`} onClick={onClose} />

      <aside className={`drawer ${open ? 'on' : ''}`}>
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
