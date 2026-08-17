import { useEffect, useState } from 'react'
import { formatMoney } from '../sales'
import { getPaper, setPaper, PAYMENT_LABELS, type Paper, type Receipt } from '../receipt'

const AUTO_KEY = 'pos.receipt_auto'

/**
 * The printable receipt.
 *
 * Printed through the browser rather than driven directly, because that is what
 * a till actually has: a thermal printer installed on the machine as a normal
 * system printer. Anything cleverer (a native bridge, ESC/POS over Bluetooth)
 * needs software on the device and stops working the first time the shop swaps
 * printers.
 *
 * The layout is one narrow column with no colour and no background fill — thermal
 * paper has no colour and prints a filled block as a smear. Everything is sized
 * in mm so 58mm and 80mm paper both come out right.
 */
export function ReceiptView({
  receipt,
  onClose,
}: {
  receipt: Receipt
  onClose: () => void
}) {
  const [paper, setPaperState] = useState<Paper>(getPaper)
  const [auto, setAuto] = useState(() => localStorage.getItem(AUTO_KEY) === '1')

  // Auto-print is opt-in and remembered: a busy counter wants the paper to come
  // out without a second tap, and a shop with no printer must not get a dialog
  // in its face after every sale.
  useEffect(() => {
    if (!auto) return
    const timer = window.setTimeout(() => window.print(), 250)
    return () => window.clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal receipt-modal" onClick={(e) => e.stopPropagation()}>
        <div className="no-print">
          <h2>Chek</h2>

          <div className="field">
            <label>Qog'oz kengligi</label>
            <div className="seg">
              {(['58mm', '80mm'] as Paper[]).map((p) => (
                <button
                  key={p}
                  type="button"
                  className={paper === p ? 'on' : ''}
                  onClick={() => {
                    setPaper(p)
                    setPaperState(p)
                  }}
                >
                  {p}
                </button>
              ))}
            </div>
          </div>

          <label className="check-row">
            <input
              type="checkbox"
              checked={auto}
              onChange={(e) => {
                setAuto(e.target.checked)
                localStorage.setItem(AUTO_KEY, e.target.checked ? '1' : '0')
              }}
            />
            <span>Har sotuvdan keyin avtomat chiqarish</span>
          </label>
        </div>

        {/* The only thing @media print keeps on the page. */}
        <div className={`receipt paper-${paper}`} id="pos-receipt">
          <div className="r-center r-shop">{receipt.shopName}</div>
          <div className="r-center r-sub">
            Chek № {receipt.no}
            {receipt.kind === 'pending' && ' (yuborilmagan)'}
          </div>
          <div className="r-center r-sub">
            {new Date(receipt.occurredAt).toLocaleString('uz-UZ')}
          </div>
          <div className="r-center r-sub">Sotuvchi: {receipt.sellerName}</div>

          <div className="r-rule" />

          {receipt.lines.map((line, i) => (
            <div className="r-line" key={i}>
              <div className="r-name">{line.name}</div>
              <div className="r-calc">
                <span>
                  {formatMoney(line.quantity)} × {formatMoney(line.price)}
                </span>
                <span>{formatMoney(line.total)}</span>
              </div>
            </div>
          ))}

          <div className="r-rule" />

          {receipt.discount > 0 && (
            <>
              <div className="r-row">
                <span>Jami</span>
                <span>{formatMoney(receipt.subtotal)}</span>
              </div>
              <div className="r-row">
                <span>Chegirma</span>
                <span>− {formatMoney(receipt.discount)}</span>
              </div>
            </>
          )}

          <div className="r-row r-total">
            <span>TO'LASH</span>
            <span>
              {formatMoney(receipt.total)} {receipt.currency}
            </span>
          </div>

          {receipt.kind === 'credit' ? (
            <>
              <div className="r-row">
                <span>NASIYA</span>
                <span>{formatMoney(receipt.owed)}</span>
              </div>
              {receipt.clientName && (
                <div className="r-row">
                  <span>Mijoz</span>
                  <span>{receipt.clientName}</span>
                </div>
              )}
            </>
          ) : (
            <>
              <div className="r-row">
                <span>{receipt.paymentType ? PAYMENT_LABELS[receipt.paymentType] : 'To\'landi'}</span>
                <span>{formatMoney(receipt.paid)}</span>
              </div>
              {receipt.change > 0 && (
                <div className="r-row">
                  <span>Qaytim</span>
                  <span>{formatMoney(receipt.change)}</span>
                </div>
              )}
            </>
          )}

          <div className="r-rule" />
          <div className="r-center r-sub">Xaridingiz uchun rahmat!</div>
          <div className="r-center r-tiny">pDaftar POS</div>
        </div>

        <div className="no-print" style={{ display: 'flex', gap: 8, marginTop: 14 }}>
          <button type="button" className="ghost" style={{ flex: 1 }} onClick={onClose}>
            Yopish
          </button>
          <button type="button" className="primary" style={{ flex: 2 }} onClick={() => window.print()}>
            Chiqarish
          </button>
        </div>
      </div>
    </div>
  )
}
