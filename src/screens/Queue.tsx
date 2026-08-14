import { useLiveQuery } from 'dexie-react-hooks'
import { db, type OutboxItem } from '../db'

const STATUS_LABELS: Record<OutboxItem['status'], string> = {
  queued: 'Navbatda',
  sent: 'Yuborildi',
  failed: 'Rad etildi',
  error: 'Xatolik',
}

/**
 * The outbox, visible.
 *
 * A queue the cashier cannot see is a queue nobody trusts. When the shop asks
 * "did this morning's sales go through?", this is the answer — and the
 * `error` rows are the ones that need a person, because the server could not
 * prove whether they were written.
 */
export function Queue({ onClose }: { onClose: () => void }) {
  const items = useLiveQuery(
    () => db.outbox.orderBy('seq').reverse().limit(200).toArray(),
    [],
    [] as OutboxItem[],
  )

  const errored = items.filter((i) => i.status === 'error')

  async function discard(item: OutboxItem) {
    const ok = confirm(
      `"${item.label}" navbatdan o'chirilsinmi?\n\n` +
        'Diqqat: bu amal serverga YUBORILMAYDI. Faqat bu qurilmadagi ' +
        'yozuv o\'chadi. Serverga yozilgan-yozilmaganini avval tekshiring.',
    )
    if (ok && item.seq !== undefined) await db.outbox.delete(item.seq)
  }

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal wide" onClick={(e) => e.stopPropagation()}>
        <h2>Navbat va tarix</h2>

        {errored.length > 0 && (
          <div className="notice err">
            {errored.length} ta amalning holati nomalum. Bular avtomatik qayta yuborilmaydi —
            takroriy sotuv yozilib qolmasligi uchun. pDaftarda tekshirib, keyin qo'lda hal qiling.
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
            {item.status !== 'sent' && (
              <button className="danger ghost" onClick={() => discard(item)}>
                O'chirish
              </button>
            )}
          </div>
        ))}

        <div style={{ marginTop: 14 }}>
          <button className="ghost" style={{ width: '100%' }} onClick={onClose}>
            Yopish
          </button>
        </div>
      </div>
    </div>
  )
}
