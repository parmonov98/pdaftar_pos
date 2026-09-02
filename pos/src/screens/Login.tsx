import { useMemo, useState } from 'react'
import {
  fetchShops,
  login,
  normalizePhone,
  registerTerminal,
  setTerminalToken,
  type ShopSummary,
} from '../api'

const LAST_PHONE_KEY = 'pos.last_phone'

type Step = 'credentials' | 'shop'

/**
 * Signing in is signing in — nothing is created here.
 *
 * pDaftar's access model is already per-person: a shop invites sellers, each has
 * their own phone and password, and any of them can open the business. The POS
 * inherits that exactly. Anvar types his number and sells; Sobir types his and
 * sells. There is no till to register, no device to name, and no quota to hit.
 *
 * The device handshake still happens — it is what scopes offline operation ids
 * and attributes a sale to the machine — but it runs silently right after login
 * and the seller never sees it.
 *
 * The shop step appears only when the account has more than one shop, and it is
 * a real decision: a shop cannot be inferred from the account (the owner this
 * was tested against has twenty-two) and guessing wrong files a day's sales
 * under the wrong books.
 */
export function Login({ onReady }: { onReady: () => void }) {
  const [step, setStep] = useState<Step>('credentials')

  const [phone, setPhone] = useState(() => localStorage.getItem(LAST_PHONE_KEY) ?? '+998')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)

  const [userToken, setUserToken] = useState<string | null>(null)
  const [shops, setShops] = useState<ShopSummary[]>([])
  const [shopFilter, setShopFilter] = useState('')

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const normalized = normalizePhone(phone)
  const phoneLooksValid = normalized.length >= 12

  const visibleShops = useMemo(() => {
    const needle = shopFilter.trim().toLowerCase()
    if (needle === '') return shops
    return shops.filter((shop) => shop.name.toLowerCase().includes(needle))
  }, [shops, shopFilter])

  /** The silent half: hand this device over and go straight to selling. */
  async function connect(token: string, shopId: number) {
    const result = await registerTerminal(token, shopId)
    setTerminalToken(result.token)
    onReady()
  }

  async function handleLogin(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      const token = await login(phone, password)
      const list = await fetchShops(token)

      if (list.length === 0) {
        setError("Bu hisobda do'kon yo'q. Do'kon egasidan sizni qo'shishini so'rang.")
        return
      }

      localStorage.setItem(LAST_PHONE_KEY, normalized)

      // One shop is not a choice, so it is not shown as one. A seller in a
      // single shop goes from password straight to the sale screen.
      if (list.length === 1) {
        await connect(token, list[0].id)
        return
      }

      setUserToken(token)
      setShops(list)
      setStep('shop')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Kirishda xatolik')
    } finally {
      setBusy(false)
    }
  }

  async function pickShop(shopId: number) {
    if (!userToken) return

    setBusy(true)
    setError(null)

    try {
      await connect(userToken, shopId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Ulanishda xatolik')
      setBusy(false)
    }
  }

  function backToCredentials() {
    setStep('credentials')
    setUserToken(null)
    setShops([])
    setShopFilter('')
    setError(null)
    setPassword('')
  }

  return (
    <div className="login-wrap">
      <div className="login">
        <h1>pDaftar POS</h1>
        <p className="sub">
          {step === 'credentials'
            ? 'Telefon raqamingiz va parolingiz bilan kiring'
            : "Qaysi do'konda sotasiz?"}
        </p>

        {error && <div className="notice err">{error}</div>}

        {step === 'credentials' ? (
          <form onSubmit={handleLogin}>
            <div className="field">
              <label htmlFor="phone">Telefon raqam</label>
              <input
                id="phone"
                autoFocus
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="+998 90 123 45 67"
                inputMode="tel"
                autoComplete="username"
              />
              {phone.trim() !== '' && phone.trim() !== '+998' && (
                <div className="hint">
                  Yuboriladi: <code>{normalized || '—'}</code>
                  {!phoneLooksValid && " · raqam to'liq emas"}
                </div>
              )}
            </div>

            <div className="field">
              <label htmlFor="pw">Parol</label>
              <div className="input-affix">
                <input
                  id="pw"
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                />
                <button
                  type="button"
                  className="affix"
                  onClick={() => setShowPassword((v) => !v)}
                  tabIndex={-1}
                >
                  {showPassword ? 'Yashirish' : "Ko'rsatish"}
                </button>
              </div>
            </div>

            <button
              className="primary"
              style={{ width: '100%' }}
              disabled={busy || !phoneLooksValid || password === ''}
            >
              {busy ? 'Kirilmoqda…' : 'Kirish'}
            </button>

            <p className="hint" style={{ marginTop: 14, textAlign: 'center' }}>
              pDaftardagi hisobingiz bilan kiriladi. Do'konga qo'shilgan har bir
              sotuvchi o'z raqami bilan kira oladi.
            </p>
          </form>
        ) : (
          <>
            {shops.length > 6 && (
              <div className="field">
                <input
                  autoFocus
                  value={shopFilter}
                  onChange={(e) => setShopFilter(e.target.value)}
                  placeholder="Do'kon nomi bo'yicha qidirish…"
                />
              </div>
            )}

            <div className="shop-list">
              {visibleShops.map((shop) => (
                <button
                  key={shop.id}
                  type="button"
                  className="shop-item"
                  disabled={busy}
                  onClick={() => pickShop(shop.id)}
                >
                  {shop.name}
                </button>
              ))}
              {visibleShops.length === 0 && (
                <div className="hint">"{shopFilter}" bo'yicha do'kon topilmadi</div>
              )}
            </div>

            <button
              type="button"
              className="ghost"
              style={{ width: '100%', marginTop: 12 }}
              onClick={backToCredentials}
              disabled={busy}
            >
              Orqaga
            </button>
          </>
        )}
      </div>
    </div>
  )
}
