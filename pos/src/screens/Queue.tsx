import { useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { db, type OutboxItem } from '../db'
import { receiptOf, type Receipt } from '../receipt'
import { ReceiptView } from './Receipt'

const STATUS_LABELS: Record<OutboxItem['status'], string> = {
  queued: 'Navbatda',
  sent: 'Yuborildi',
  failed: 'Rad etildi',
  error: 'Xatolik',
}

/**
 * The outbox, visible.
 *
 * A queue the seller cannot see is a queue nobody trusts. When the shop asks
 * "did this morning's sales go through?", this is the answer — and the `error`
 * rows are the ones that need a person, because the server could not prove
 * whether they were written.
 */
export function Queue({ onClose, inline = false }: { onClose: () => void; inline?: boolean }) {
  const items = useLiveQuery(
    () => db.outbox.orderBy('seq').reverse().limit(200).toArray(),
    [],
    [] as OutboxItem[],
  )

  const [receipt, setReceipt] = useState<Receipt | null>(null)
  const errored = items.filter((i) => i.status === 'error')
  const waiting = items.filter((i) => i.status === 'queued' || i.status === 'failed')

  // A refusal the server will give again. These retry on every sync, so
  // nothing breaks and nothing ever resolves either: the count sits under
  // "kutmoqda" looking like a connection problem while a real sale goes
  // unrecorded. After three identical rejections it is a person's problem.
  const stuck = items.filter((i) => i.status === 'failed' && i.attempts >= 3)

  async function discard(item: OutboxItem) {
    const ok = confirm(
      `"${item.label}" navbatdan o'chirilsinmi?\n\n` +
        'Diqqat: bu amal serverga YUBORILMAYDI. Faqat bu qurilmadagi ' +
        "yozuv o'chadi. Serverga yozilgan-yozilmaganini avval tekshiring.",
    )
    if (ok && item.seq !== undefined) await db.outbox.delete(item.seq)
  }

  const body = (
    <>
      {errored.length > 0 && (
        <div className="notice err">
          {errored.length} ta amalning holati nomalum. Bular avtomatik qayta yuborilmaydi —
          takroriy sotuv yozilib qolmasligi uchun. pDaftarda tekshirib, keyin qo'lda hal qiling.
        </div>
      )}

      {stuck.length > 0 && (
        <div className="notice err">
          {stuck.length} ta amalni server qayta-qayta rad etmoqda. Qayta urinish yordam bermaydi —
          quyidagi xato matnini o'qing, mahsulot yoki mijoz o'chirilgan bo'lishi mumkin.
        </div>
      )}

      {items.length === 0 && <div className="cart-empty">Hozircha amal yo'q</div>}

      {items.map((item) => (
        <div className="qrow" key={item.seq}>
          <span className={`st ${item.status}`}>{STATUS_LABELS[item.status]}</span>
          <div className="grow">
            <div>{item.label}</div>
            <div style={{ color: 'var(--muted)', fontSize: 12 }}>
              {item.type} · {new Date(item.occurred_at).toLocaleString('uz-UZ')}
              {item.attempts > 0 && ` · ${item.attempts} urinish`}
            </div>
            {item.error && <div className="err">{item.error}</div>}
          </div>
          {/* The receipt matters MOST here: a sale still in the queue has
              already happened at the counter, and the customer wants paper for
              it whether or not the server has heard about it yet. */}
          {receiptOf(item) && (
            <button className="ghost" onClick={() => setReceipt(receiptOf(item))} title="Chek">
              🧾
            </button>
          )}
          {item.status !== 'sent' && (
            <button className="danger ghost" onClick={() => discard(item)}>
              O'chirish
            </button>
          )}
        </div>
      ))}
    </>
  )

  if (inline) {
    return (
      <div className="view">
        <div className="view-head">
          <h2>Navbat</h2>
          <span className="spacer" />
          <button className="ghost" onClick={onClose}>
            Sotuvga qaytish
          </button>
        </div>
        <div className="view-summary">
          {waiting.length - stuck.length} ta kutmoqda · {stuck.length} ta rad etildi ·{' '}
          {errored.length} ta tekshirish kerak
        </div>
        <div className="view-body">{body}</div>
        {receipt && <ReceiptView receipt={receipt} onClose={() => setReceipt(null)} />}
      </div>
    )
  }

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal wide" onClick={(e) => e.stopPropagation()}>
        <h2>Navbat va tarix</h2>
        {body}
        <div style={{ marginTop: 14 }}>
          <button className="ghost" style={{ width: '100%' }} onClick={onClose}>
            Yopish
          </button>
        </div>
        {receipt && <ReceiptView receipt={receipt} onClose={() => setReceipt(null)} />}
      </div>
    </div>
  )
}
