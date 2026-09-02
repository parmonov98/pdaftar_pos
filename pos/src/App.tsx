import { useCallback, useEffect, useState } from 'react'
import { AuthExpiredError, fetchMe, getTerminalToken, setTerminalToken, type MeResponse } from './api'
import { clearCache } from './db'
import { pullAll } from './sync'
import { Login } from './screens/Login'
import { Pos } from './screens/Pos'

type Phase =
  | { kind: 'booting' }
  | { kind: 'login' }
  | { kind: 'ready'; me: MeResponse }
  | { kind: 'broken'; message: string }

export default function App() {
  const [phase, setPhase] = useState<Phase>({ kind: 'booting' })

  const boot = useCallback(async () => {
    if (!getTerminalToken()) {
      setPhase({ kind: 'login' })
      return
    }

    setPhase({ kind: 'booting' })

    try {
      const me = await fetchMe()
      setPhase({ kind: 'ready', me })

      // First catalogue fill happens in the background. Blocking the till on it
      // would leave a cashier staring at a spinner when they could already be
      // selling from the copy they synced yesterday.
      void pullAll().catch(() => undefined)
    } catch (e) {
      if (e instanceof AuthExpiredError) {
        // Revoked from another device, or the user was removed from the shop.
        // The local outbox is deliberately NOT cleared — it may hold sales this
        // device is the only copy of.
        setTerminalToken(null)
        setPhase({ kind: 'login' })
        return
      }

      // Anything else — server down, no network on first launch. Not a reason
      // to log out and lose the queued sales; offer a retry instead.
      setPhase({
        kind: 'broken',
        message: e instanceof Error ? e.message : 'Serverga ulanib bo\'lmadi',
      })
    }
  }, [])

  useEffect(() => {
    void boot()
  }, [boot])

  function logout() {
    setTerminalToken(null)
    void clearCache()
    setPhase({ kind: 'login' })
  }

  if (phase.kind === 'booting') {
    return (
      <div className="login-wrap">
        <div className="login">
          <h1>pDaftar POS</h1>
          <p className="sub">Yuklanmoqda…</p>
        </div>
      </div>
    )
  }

  if (phase.kind === 'broken') {
    return (
      <div className="login-wrap">
        <div className="login">
          <h1>Ulanib bo'lmadi</h1>
          <div className="notice err">{phase.message}</div>
          <p className="sub">
            Navbatdagi sotuvlar saqlanib turibdi — ular yo'qolmaydi. Internetni tekshirib,
            qayta urinib ko'ring.
          </p>
          <button className="primary" style={{ width: '100%' }} onClick={() => void boot()}>
            Qayta urinish
          </button>
          <button className="ghost" style={{ width: '100%', marginTop: 8 }} onClick={logout}>
            Boshqa hisob bilan kirish
          </button>
        </div>
      </div>
    )
  }

  if (phase.kind === 'login') {
    return <Login onReady={() => void boot()} />
  }

  return <Pos me={phase.me} onLogout={logout} />
}
