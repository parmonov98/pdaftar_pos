import { useMemo, useState } from 'react'
import {
  login,
  normalizePhone,
  register,
  registerTerminal,
  setTerminalToken,
  type AuthResult,
  type ShopSummary,
} from '../api'

const LAST_PHONE_KEY = 'pos.last_phone'

type Step = 'credentials' | 'register' | 'shop'

/**
 * Signing in is signing in — nothing is created here.
 *
 * The POS owns its accounts. It used to sign in against pDaftar's mobile API,
 * which worked only while the till was served from pDaftar's own origin — on
 * its own domain and database that route does not exist. So a shop that has
 * never heard of pDaftar can register here and start selling.
 *
 * Access stays per-person: each seller has their own number and password, and
 * any of them can open the business. There is no till to name and no quota to
 * hit.
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

  // Registration only. Kept separate from the login fields so switching
  // between the two never carries a half-typed value across.
  const [regName, setRegName] = useState('')
  const [regShopName, setRegShopName] = useState('')

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

  /**
   * What happens after either login or registration succeeds.
   *
   * One path, so a freshly registered owner and a returning seller cannot end
   * up in different states — the new owner has exactly one shop and should go
   * straight to selling, which is the same rule as any other single-shop user.
   */
  async function afterAuth(auth: AuthResult) {
    localStorage.setItem(LAST_PHONE_KEY, normalizePhone(phone))

    if (auth.shops.length === 0) {
      setError("Bu hisobda do'kon yo'q. Do'kon egasidan sizni qo'shishini so'rang.")
      return
    }

    // One shop is not a choice, so it is not shown as one.
    if (auth.shops.length === 1) {
      await connect(auth.token, auth.shops[0].id)
      return
    }

    setUserToken(auth.token)
    setShops(auth.shops)
    setStep('shop')
  }

  async function handleLogin(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      await afterAuth(await login(phone, password))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Kirishda xatolik')
    } finally {
      setBusy(false)
    }
  }

  async function handleRegister(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      await afterAuth(
        await register({ name: regName, phone, password, shopName: regShopName }),
      )
    } catch (e) {
      setError(e instanceof Error ? e.message : "Ro'yxatdan o'tishda xatolik")
    } finally {
      setBusy(false)
    }
  }

  /** The shop step's choice: open a till in the shop they picked. */
  async function pickShop(shopId: number) {
    if (userToken === null) return
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
          {step === 'credentials' && 'Telefon raqamingiz va parolingiz bilan kiring'}
          {step === 'register' && "Yangi hisob va do'kon yarating"}
          {step === 'shop' && "Qaysi do'konda sotasiz?"}
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
                {/* Reachable by Tab. It was tabIndex={-1} to keep the path
                    from the password straight to Kirish — which left the one
                    control that tells a cashier WHY their password is being
                    refused available only to a mouse. */}
                <button
                  type="button"
                  className="affix"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-pressed={showPassword}
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
              Do'konga qo'shilgan har bir sotuvchi o'z raqami bilan kira oladi.
            </p>

            <p className="hint" style={{ marginTop: 10, textAlign: 'center' }}>
              Hisobingiz yo'qmi?{' '}
              <button
                type="button"
                className="linklike"
                onClick={() => {
                  setStep('register')
                  setError(null)
                  setPassword('')
                }}
              >
                Ro'yxatdan o'ting
              </button>
            </p>
          </form>
        ) : step === 'register' ? (
          <form onSubmit={handleRegister}>
            <div className="field">
              <label htmlFor="rname">Ismingiz</label>
              <input
                id="rname"
                autoFocus
                value={regName}
                onChange={(e) => setRegName(e.target.value)}
                placeholder="Anvar"
                autoComplete="name"
              />
            </div>

            <div className="field">
              <label htmlFor="rshop">Do'kon nomi</label>
              <input
                id="rshop"
                value={regShopName}
                onChange={(e) => setRegShopName(e.target.value)}
                placeholder="Anvar Market"
                autoComplete="organization"
              />
            </div>

            <div className="field">
              <label htmlFor="rphone">Telefon raqam</label>
              <input
                id="rphone"
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
              <label htmlFor="rpw">Parol</label>
              <div className="input-affix">
                <input
                  id="rpw"
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="new-password"
                />
                {/* Reachable by Tab. It was tabIndex={-1} to keep the path
                    from the password straight to Kirish — which left the one
                    control that tells a cashier WHY their password is being
                    refused available only to a mouse. */}
                <button
                  type="button"
                  className="affix"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-pressed={showPassword}
                >
                  {showPassword ? 'Yashirish' : "Ko'rsatish"}
                </button>
              </div>
              {/* Stated before they submit, not as a rejection afterwards. */}
              <div className="hint">Kamida 6 ta belgi.</div>
            </div>

            <button
              className="primary"
              style={{ width: '100%' }}
              disabled={
                busy ||
                !phoneLooksValid ||
                password.length < 6 ||
                regName.trim() === '' ||
                regShopName.trim() === ''
              }
            >
              {busy ? "Yaratilmoqda…" : "Ro'yxatdan o'tish"}
            </button>

            <p className="hint" style={{ marginTop: 14, textAlign: 'center' }}>
              Hisobingiz bormi?{' '}
              <button
                type="button"
                className="linklike"
                onClick={() => {
                  setStep('credentials')
                  setError(null)
                  setPassword('')
                }}
              >
                Kirish
              </button>
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
