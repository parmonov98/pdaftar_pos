import { useState } from 'react'
import { fetchShops, login, registerTerminal, setTerminalToken, type ShopSummary } from '../api'

/**
 * Two steps, because they are two different decisions.
 *
 * First "who are you" (a pDaftar account), then "which shop is this till in".
 * A shop cannot be inferred from the account — sellers commonly belong to more
 * than one — and guessing wrong would file a day's sales under the wrong books.
 *
 * The user token from step one is held in component state only, never stored.
 * See the note in api.ts about why a till must not keep it.
 */
export function Login({ onReady }: { onReady: () => void }) {
  const [phone, setPhone] = useState('+998')
  const [password, setPassword] = useState('')
  const [terminalName, setTerminalName] = useState('Kassa 1')

  const [userToken, setUserToken] = useState<string | null>(null)
  const [shops, setShops] = useState<ShopSummary[]>([])
  const [shopId, setShopId] = useState<number | null>(null)

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleLogin(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      const token = await login(phone.trim(), password)
      const list = await fetchShops(token)

      if (list.length === 0) {
        setError('Bu foydalanuvchida do\'kon yo\'q. Avval pDaftarda do\'kon yarating.')
        return
      }

      setUserToken(token)
      setShops(list)
      setShopId(list[0].id)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Kirishda xatolik')
    } finally {
      setBusy(false)
    }
  }

  async function handleRegister(event: React.FormEvent) {
    event.preventDefault()
    if (!userToken || shopId === null) return

    setBusy(true)
    setError(null)

    try {
      const result = await registerTerminal(userToken, shopId, terminalName.trim() || 'Kassa')
      setTerminalToken(result.token)
      onReady()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Kassani ulashda xatolik')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="login-wrap">
      <div className="login">
        <h1>pDaftar POS</h1>
        <p className="sub">
          {userToken === null
            ? 'pDaftar hisobingiz bilan kiring'
            : 'Bu kassa qaysi do\'konda ishlaydi?'}
        </p>

        {error && <div className="notice err">{error}</div>}

        {userToken === null ? (
          <form onSubmit={handleLogin}>
            <div className="field">
              <label htmlFor="phone">Telefon raqam</label>
              <input
                id="phone"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="+998901234567"
                autoComplete="username"
              />
            </div>
            <div className="field">
              <label htmlFor="pw">Parol</label>
              <input
                id="pw"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="current-password"
              />
            </div>
            <button className="primary" style={{ width: '100%' }} disabled={busy}>
              {busy ? 'Kirilmoqda…' : 'Kirish'}
            </button>
          </form>
        ) : (
          <form onSubmit={handleRegister}>
            <div className="field">
              <label htmlFor="shop">Do'kon</label>
              <select
                id="shop"
                value={shopId ?? ''}
                onChange={(e) => setShopId(Number(e.target.value))}
              >
                {shops.map((shop) => (
                  <option key={shop.id} value={shop.id}>
                    {shop.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="field">
              <label htmlFor="tname">Kassa nomi</label>
              <input id="tname" value={terminalName} onChange={(e) => setTerminalName(e.target.value)} />
            </div>
            <div className="notice warn">
              Kassalar soni do'kondagi pDaftar foydalanuvchilari soniga teng. Limit tugasa,
              do'konga foydalanuvchi qo'shing yoki eski kassani o'chiring.
            </div>
            <button className="primary" style={{ width: '100%' }} disabled={busy}>
              {busy ? 'Ulanmoqda…' : 'Kassani ulash'}
            </button>
          </form>
        )}
      </div>
    </div>
  )
}
