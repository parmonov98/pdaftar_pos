import { useEffect, useMemo, useState } from 'react'
import {
  fetchShopTerminals,
  fetchShops,
  getDeviceId,
  KassaLimitError,
  login,
  normalizePhone,
  registerTerminal,
  revokeTerminal,
  setTerminalToken,
  type ShopSummary,
  type TerminalSummary,
} from '../api'

const LAST_PHONE_KEY = 'pos.last_phone'

type Step = 'credentials' | 'shop'

/**
 * Two steps, because they are two different decisions.
 *
 * First "who are you" (a pDaftar account), then "which shop is this till in".
 * A shop cannot be inferred from the account — the owner of the shop this was
 * first tested against has twenty-two of them — and guessing wrong would file a
 * day's sales under the wrong books.
 *
 * The user token from step one is held in component state only, never stored.
 * See the note in api.ts about why a till must not keep it.
 */
export function Login({ onReady }: { onReady: () => void }) {
  const [step, setStep] = useState<Step>('credentials')

  const [phone, setPhone] = useState(() => localStorage.getItem(LAST_PHONE_KEY) ?? '+998')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)

  const [userToken, setUserToken] = useState<string | null>(null)
  const [shops, setShops] = useState<ShopSummary[]>([])
  const [shopId, setShopId] = useState<number | null>(null)
  const [shopFilter, setShopFilter] = useState('')
  const [terminalName, setTerminalName] = useState('Kassa 1')

  // Populated when the shop is full; turns a dead end into a decision.
  const [slotHolders, setSlotHolders] = useState<TerminalSummary[] | null>(null)
  const [kassa, setKassa] = useState<{ used: number; limit: number } | null>(null)

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const normalized = normalizePhone(phone)
  const phoneLooksValid = normalized.length >= 12

  const visibleShops = useMemo(() => {
    const needle = shopFilter.trim().toLowerCase()
    if (needle === '') return shops
    return shops.filter((shop) => shop.name.toLowerCase().includes(needle))
  }, [shops, shopFilter])

  // Whenever the chosen shop changes, show how many slots it has left BEFORE
  // the cashier fills in a name and presses a button that cannot succeed.
  useEffect(() => {
    if (step !== 'shop' || userToken === null || shopId === null) return

    let cancelled = false
    setSlotHolders(null)

    fetchShopTerminals(userToken, shopId)
      .then((result) => {
        if (cancelled) return
        setKassa(result.kassa)
        setSlotHolders(result.kassa.used >= result.kassa.limit ? result.terminals : null)
      })
      .catch(() => {
        // Non-fatal: registration will report the real answer. No point
        // blocking the screen over a pre-flight count.
        if (!cancelled) setKassa(null)
      })

    return () => {
      cancelled = true
    }
  }, [step, userToken, shopId])

  async function handleLogin(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      const token = await login(phone, password)
      const list = await fetchShops(token)

      if (list.length === 0) {
        setError("Bu foydalanuvchida do'kon yo'q. Avval pDaftarda do'kon yarating.")
        return
      }

      localStorage.setItem(LAST_PHONE_KEY, normalized)
      setUserToken(token)
      setShops(list)
      setShopId(list[0].id)
      setStep('shop')
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
      if (e instanceof KassaLimitError) {
        setSlotHolders(e.terminals)
        setKassa(e.kassa)
        setError(e.message)
        return
      }
      setError(e instanceof Error ? e.message : 'Kassani ulashda xatolik')
    } finally {
      setBusy(false)
    }
  }

  async function handleRevoke(terminal: TerminalSummary) {
    if (!userToken) return

    const ok = confirm(
      `"${terminal.name}" kassasi o'chirilsinmi?\n\n` +
        "O'sha qurilmadagi tokeni bekor qilinadi va u qayta kirishi kerak bo'ladi. " +
        'Sotuv tarixi saqlanib qoladi.',
    )
    if (!ok) return

    setBusy(true)
    setError(null)

    try {
      await revokeTerminal(userToken, terminal.id)
      const refreshed = await fetchShopTerminals(userToken, shopId!)
      setKassa(refreshed.kassa)
      setSlotHolders(refreshed.kassa.used >= refreshed.kassa.limit ? refreshed.terminals : null)
    } catch (e) {
      setError(e instanceof Error ? e.message : "O'chirishda xatolik")
    } finally {
      setBusy(false)
    }
  }

  function backToCredentials() {
    setStep('credentials')
    setUserToken(null)
    setShops([])
    setShopId(null)
    setShopFilter('')
    setSlotHolders(null)
    setKassa(null)
    setError(null)
    setPassword('')
  }

  return (
    <div className="login-wrap">
      <div className="login">
        <h1>pDaftar POS</h1>
        <p className="sub">
          {step === 'credentials'
            ? 'pDaftar hisobingiz bilan kiring'
            : "Bu kassa qaysi do'konda ishlaydi?"}
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
                  {!phoneLooksValid && ' · raqam to\'liq emas'}
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
          </form>
        ) : (
          <form onSubmit={handleRegister}>
            <div className="field">
              <label htmlFor="shop">
                Do'kon {shops.length > 1 && <span className="muted">· {shops.length} ta</span>}
              </label>

              {/* A filter only earns its space once the list is long enough to
                  scroll past the one you want. */}
              {shops.length > 6 && (
                <input
                  style={{ marginBottom: 8 }}
                  value={shopFilter}
                  onChange={(e) => setShopFilter(e.target.value)}
                  placeholder="Do'kon nomi bo'yicha qidirish…"
                />
              )}

              <select
                id="shop"
                size={shops.length > 6 ? 7 : undefined}
                value={shopId ?? ''}
                onChange={(e) => setShopId(Number(e.target.value))}
              >
                {visibleShops.map((shop) => (
                  <option key={shop.id} value={shop.id}>
                    {shop.name}
                  </option>
                ))}
              </select>

              {visibleShops.length === 0 && (
                <div className="hint">"{shopFilter}" bo'yicha do'kon topilmadi</div>
              )}
            </div>

            <div className="field">
              <label htmlFor="tname">Kassa nomi</label>
              <input
                id="tname"
                value={terminalName}
                onChange={(e) => setTerminalName(e.target.value)}
              />
              <div className="hint">
                Qurilma ID: <code>{getDeviceId().slice(0, 16)}…</code>
              </div>
            </div>

            {kassa && (
              <div className={`notice ${kassa.used >= kassa.limit ? 'err' : 'warn'}`}>
                Kassa: <strong>{kassa.used}/{kassa.limit}</strong> band.
                {kassa.used >= kassa.limit
                  ? " Yangi kassa ochish uchun quyidagilardan birini o'chiring yoki do'konga foydalanuvchi qo'shing."
                  : " Kassalar soni do'kondagi pDaftar foydalanuvchilari soniga teng."}
              </div>
            )}

            {slotHolders && slotHolders.length > 0 && (
              <div className="field">
                <label>Slotni band qilgan kassalar</label>
                {slotHolders
                  .filter((t) => t.is_active)
                  .map((terminal) => (
                    <div className="qrow" key={terminal.id}>
                      <div className="grow">
                        <div>{terminal.name}</div>
                        <div style={{ color: 'var(--muted)', fontSize: 12 }}>
                          {terminal.provider}
                          {terminal.last_seen_at
                            ? ` · oxirgi faollik ${new Date(terminal.last_seen_at).toLocaleString('uz-UZ')}`
                            : ' · hech qachon ulanmagan'}
                        </div>
                      </div>
                      <button
                        type="button"
                        className="danger ghost"
                        disabled={busy}
                        onClick={() => handleRevoke(terminal)}
                      >
                        O'chirish
                      </button>
                    </div>
                  ))}
              </div>
            )}

            <div style={{ display: 'flex', gap: 8 }}>
              <button type="button" className="ghost" style={{ flex: 1 }} onClick={backToCredentials}>
                Orqaga
              </button>
              <button
                className="primary"
                style={{ flex: 2 }}
                disabled={busy || shopId === null || (kassa !== null && kassa.used >= kassa.limit)}
              >
                {busy ? 'Ulanmoqda…' : 'Kassani ulash'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
