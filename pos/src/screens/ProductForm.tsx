import { useEffect, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { createProduct, updateProduct } from '../api'
import { db, type Product, type Unit } from '../db'
import { pullAll } from '../sync'

/**
 * Adding and editing what the shop sells.
 *
 * The POS owns its catalogue now, so a shop that has just registered has
 * nothing to sell until someone types the first product in. That makes this
 * the screen standing between a new install and its first sale.
 *
 * Online only, and it says so rather than failing at submit: the cart
 * addresses products by the server's id, and a product created offline has
 * none yet. Selling itself stays fully offline.
 */
export function ProductForm({
  product,
  onClose,
}: {
  product: Product | null
  onClose: () => void
}) {
  const editing = product !== null

  const units = useLiveQuery(() => db.units.toArray(), [], [] as Unit[])
  const currencies = useLiveQuery(() => db.currencies.toArray(), [], [] as { id: number }[])
  const currencyId = product?.currency_id ?? currencies[0]?.id ?? null

  const [name, setName] = useState(product?.name ?? '')
  const [barcode, setBarcode] = useState(product?.barcode ?? '')
  const [price, setPrice] = useState(product?.price != null ? String(product.price) : '')
  const [unitId, setUnitId] = useState<number | null>(product?.unit_id ?? null)

  // null quantity means "never inventoried", which is a different thing from
  // zero: a shop that does not count this item is not a shop that has run out
  // of it. The toggle keeps the two distinguishable instead of turning every
  // untracked product into an out-of-stock badge.
  const [tracked, setTracked] = useState(product ? product.quantity !== null : true)
  const [quantity, setQuantity] = useState(product?.quantity != null ? String(product.quantity) : '0')
  const [threshold, setThreshold] = useState(
    product?.low_stock_threshold != null ? String(product.low_stock_threshold) : '',
  )

  /*
   * A second way to sell the same thing — "1 karobka = 12 dona".
   *
   * Offered on create only. Changing a ratio after sales exist is a
   * different and more dangerous act: old lines keep their snapshot, but
   * anyone reading the catalogue would see a number that no longer matches
   * what was sold. That belongs on its own screen, with a warning.
   */
  const [packUnitId, setPackUnitId] = useState<number | null>(null)
  const [packPer, setPackPer] = useState('')
  const [packPrice, setPackPrice] = useState('')

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [online, setOnline] = useState(navigator.onLine)

  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => {
      window.removeEventListener('online', on)
      window.removeEventListener('offline', off)
    }
  }, [])

  // A unit is not optional in practice — "2" of what? — so a new product picks
  // up the shop's default rather than making the seller choose every time.
  useEffect(() => {
    if (unitId === null && units.length > 0) {
      setUnitId((units.find((u) => u.is_default) ?? units[0]).id)
    }
  }, [units, unitId])

  const nameOk = name.trim() !== ''
  const priceOk = price.trim() !== '' && Number.isFinite(Number(price)) && Number(price) >= 0
  const canSubmit = nameOk && priceOk && online && !busy

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    const input = {
      name: name.trim(),
      barcode: barcode.trim() === '' ? null : barcode.trim(),
      price: Number(price),
      unit_id: unitId,
      // What the price above is denominated in. It was being left off, so
      // every product the till created stored a price with no currency
      // beside it — and the server's own pricing then refused to use that
      // column, because it checks the two match.
      currency_id: currencyId,
      low_stock_threshold: threshold.trim() === '' ? null : Number(threshold),
    }

    const packPerNum = Number(packPer)
    const wantsPack =
      !editing && packUnitId !== null && packPerNum > 0 && packUnitId !== unitId

    try {
      if (editing) {
        // Stock is deliberately absent here. It moves through sales,
        // deliveries and stocktakes — each of which leaves a ledger entry —
        // and letting this form overwrite the number would erase that history
        // with a value somebody typed.
        await updateProduct(product.id, input)
      } else {
        await createProduct({
          ...input,
          quantity: tracked ? Number(quantity || 0) : null,
          units: wantsPack
            ? [
                {
                  unit_id: packUnitId,
                  numerator: packPerNum,
                  denominator: 1,
                  prices:
                    packPrice.trim() === ''
                      ? []
                      : [{ currency_id: currencyId, amount: Number(packPrice) }],
                },
              ]
            : undefined,
        })
      }

      // Pull straight back so the new row is in the local catalogue and
      // scannable before this closes, rather than after the next sync.
      await pullAll()
      onClose()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Saqlashda xatolik')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>{editing ? 'Mahsulotni tahrirlash' : 'Yangi mahsulot'}</h2>

        {!online && (
          <div className="notice err">
            Internet yo'q. Mahsulot qo'shish uchun ulanish kerak — sotuv esa oflayn ishlayveradi.
          </div>
        )}
        {error && <div className="notice err">{error}</div>}

        <form onSubmit={submit}>
          <div className="field">
            <label htmlFor="p-name">Nomi</label>
            <input
              id="p-name"
              autoFocus
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Coca-Cola 1.5L"
            />
          </div>

          <div className="field">
            <label htmlFor="p-barcode">Barcode</label>
            <input
              id="p-barcode"
              value={barcode}
              onChange={(e) => setBarcode(e.target.value)}
              placeholder="Skanerlang yoki qo'lda kiriting"
              inputMode="numeric"
            />
            <div className="hint">Bo'sh qoldirsangiz, mahsulot nomi bo'yicha topiladi.</div>
          </div>

          <div className="two-col">
            <div className="field">
              <label htmlFor="p-price">Narxi</label>
              <input
                id="p-price"
                value={price}
                onChange={(e) => setPrice(e.target.value)}
                inputMode="decimal"
                placeholder="12000"
              />
            </div>

            <div className="field">
              <label htmlFor="p-unit">Birlik</label>
              <select
                id="p-unit"
                value={unitId ?? ''}
                onChange={(e) => setUnitId(e.target.value === '' ? null : Number(e.target.value))}
              >
                {units.map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.short_name || u.name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {!editing && (
            <div className="field">
              <label className="check-row">
                <input
                  type="checkbox"
                  checked={tracked}
                  onChange={(e) => setTracked(e.target.checked)}
                />
                Qoldiq hisobga olinsin
              </label>

              {tracked ? (
                <input
                  value={quantity}
                  onChange={(e) => setQuantity(e.target.value)}
                  inputMode="decimal"
                  placeholder="Boshlang'ich qoldiq"
                  style={{ marginTop: 8 }}
                />
              ) : (
                <div className="hint">
                  Xizmat yoki tarozi mahsuloti uchun. Qoldiq ko'rsatilmaydi va kamaymaydi.
                </div>
              )}
            </div>
          )}

          {tracked && (
            <div className="field">
              <label htmlFor="p-threshold">Kam qoldiq ogohlantirishi</label>
              <input
                id="p-threshold"
                value={threshold}
                onChange={(e) => setThreshold(e.target.value)}
                inputMode="decimal"
                placeholder="Masalan: 5 — bo'sh qoldirsangiz ogohlantirilmaydi"
              />
            </div>
          )}

          {!editing && units.length > 1 && (
            <div className="field">
              <label>Yirik birlik (ixtiyoriy)</label>
              <div className="pack-row">
                <span className="pack-lead">1</span>
                <select
                  value={packUnitId ?? ''}
                  onChange={(e) => setPackUnitId(e.target.value === '' ? null : Number(e.target.value))}
                  aria-label="Yirik birlik"
                >
                  <option value="">—</option>
                  {units
                    .filter((u) => u.id !== unitId)
                    .map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.short_name || u.name}
                      </option>
                    ))}
                </select>
                <span className="pack-lead">=</span>
                <input
                  value={packPer}
                  onChange={(e) => setPackPer(e.target.value)}
                  inputMode="numeric"
                  placeholder="12"
                  aria-label="Nechta asosiy birlik"
                  disabled={packUnitId === null}
                />
                <span className="pack-lead">
                  {units.find((u) => u.id === unitId)?.short_name ?? ''}
                </span>
              </div>

              {packUnitId !== null && (
                <input
                  value={packPrice}
                  onChange={(e) => setPackPrice(e.target.value)}
                  inputMode="decimal"
                  placeholder="Yirik birlik narxi (bo'sh — sotib bo'lmaydi)"
                  style={{ marginTop: 8 }}
                  aria-label="Yirik birlik narxi"
                />
              )}

              <div className="hint">
                Masalan: 1 karobka = 12 dona. Kassada har qatorda qaysi birlikda sotilayotganini
                tanlash mumkin bo'ladi.
              </div>
            </div>
          )}

          {editing && (
            <div className="hint" style={{ marginBottom: 12 }}>
              Qoldiqni bu yerdan o'zgartirib bo'lmaydi — u sotuv va kirim orqali harakatlanadi.
            </div>
          )}

          <div className="actions">
            <button type="button" className="ghost" onClick={onClose}>
              Bekor qilish
            </button>
            <button className="primary" disabled={!canSubmit}>
              {busy ? 'Saqlanmoqda…' : 'Saqlash'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
