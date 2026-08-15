import { useEffect, useState } from 'react'
import { getDeviceId, type MeResponse, type TerminalSummary } from '../api'

/**
 * Which devices have the shop's POS open.
 *
 * Not a quota screen — there is no limit. It answers "is the tablet in the back
 * still signed in?" and lets an owner cut off a device that was lost or handed
 * to someone who has left.
 *
 * Read through the device token rather than the user token, because by the time
 * someone is looking at this they are already signed in on this device.
 */
export function Devices({ me }: { me: MeResponse }) {
  const [rows, setRows] = useState<TerminalSummary[]>([])
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const thisDevice = getDeviceId()

  async function load() {
    setBusy(true)
    setError(null)
    try {
      const res = await fetch('/api/pos/v1/terminals', {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${localStorage.getItem('pos.terminal_token') ?? ''}`,
        },
      })
      const body = await res.json()
      if (!res.ok) throw new Error(body?.message ?? 'Xatolik')
      setRows(body.data ?? [])
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Yuklab bo\'lmadi')
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  return (
    <div className="view">
      <div className="view-head">
        <h2>Qurilmalar</h2>
        <span className="spacer" />
        <button className="ghost" onClick={() => void load()} disabled={busy}>
          {busy ? 'Yuklanmoqda…' : 'Yangilash'}
        </button>
      </div>

      {error && <div className="notice err">{error}</div>}

      <div className="view-summary">
        {me.shop.name} · {rows.filter((r) => r.is_active).length} ta faol qurilma
        <span className="muted"> · limit yo'q, do'konga kira oladigan har kim sotadi</span>
      </div>

      <div className="view-body">
        {rows.map((row) => (
          <div className="list-row" key={row.id}>
            <span className="grow">
              <span className="nm">
                {row.name}
                {row.device_id === thisDevice && <span className="tag">shu qurilma</span>}
                {!row.is_active && <span className="tag danger">uzilgan</span>}
              </span>
              <span className="sub">
                {row.provider}
                {' · '}
                {row.last_seen_at
                  ? `oxirgi faollik ${new Date(row.last_seen_at).toLocaleString('uz-UZ')}`
                  : 'hech qachon ulanmagan'}
              </span>
            </span>
          </div>
        ))}

        {!busy && rows.length === 0 && <div className="cart-empty">Qurilma yo'q</div>}
      </div>
    </div>
  )
}
