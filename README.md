# pDaftar POS

Kassa (till) ilovasi — React + TypeScript + Vite, offline ishlaydi.

Bu ilova pDaftar backendining **mijozi**. O'z buxgalteriyasi yo'q: brauzerdagi baza —
katalog nusxasi (kesh) va yuborilmagan amallar navbati (outbox), boshqa hech narsa.
Qoldiq har doim serverdagi `stock_movements` ledgeridan keladi.

## Ishga tushirish (lokal)

Backend Dockerda ishlab turishi kerak (`backend/` papkasida `docker compose up -d`,
API `http://localhost:8083`).

```bash
npm install
npm run dev          # http://localhost:5174
```

Vite `/api` ni backendga proxy qiladi, shuning uchun CORS muammosi yo'q.
Boshqa manzil kerak bo'lsa:

```bash
POS_API_TARGET=http://192.168.1.50:8083 npm run dev
```

Boshqa buyruqlar:

```bash
npm run build        # dist/ ga yig'ish
npm run preview      # yig'ilganini ko'rish
```

## Kirish

1. pDaftar telefon raqami va paroli bilan kiriladi.
2. Do'kon tanlanadi va kassaga nom beriladi.
3. Ilova `POST /api/pos/v1/terminals/register` orqali **kassa tokeni** oladi.

User tokeni saqlanmaydi — faqat ro'yxatdan o'tish uchun ishlatiladi va tashlab yuboriladi.
Umumiy kassada owner tokenini saqlash xavfli.

**Kassalar soni** do'kondagi pDaftar foydalanuvchilari soniga teng. Limit tugasa
403 qaytadi — do'konga foydalanuvchi qo'shish yoki eski kassani o'chirish kerak.

Bir xil qurilma (`device_id` localStorage'da saqlanadi) qayta ro'yxatdan o'tsa,
eski qatorni qayta ishlatadi va eski tokenni bekor qiladi — yangi slot yemaydi.

## Offline qanday ishlaydi

**Har bir yozuv avval navbatga yoziladi, keyin yuboriladi** — internet bor-yo'qligidan
qat'i nazar. Sotuv brauzer bazasiga tushgandan keyingina tarmoqqa urinib ko'riladi,
shuning uchun so'rov o'rtasida ulanish uzilsa ham sotuv yo'qolmaydi.

Har bir amalga `client_operation_id` (UUID) beriladi — **kassir amalni bajargan
paytda**, yuborish paytida emas. Server shu ID bo'yicha takroriy yuborishni taniydi
va ikkinchi marta yozmaydi (`replayed: true`).

### Navbat holatlari

| Holat | Ma'nosi | Qayta yuboriladimi |
|---|---|---|
| `queued` | Hali yuborilmagan | Ha |
| `sent` | Server qabul qildi | — |
| `failed` | Server yozishdan **oldin** rad etdi (validatsiya) | Ha |
| `error` | Xatolik — yozilgan-yozilmagani nomalum | **Yo'q** |

`error` avtomatik qayta yuborilmaydi. Sabab: amal serverda bajarilgan bo'lishi mumkin
va uni qayta yuborish bitta sotuvni ikki marta yozib qo'yishi mumkin. Bunday qatorlar
"Navbat" oynasida qizil bilan ko'rsatiladi va odam tekshirishini talab qiladi.

### Ilovaning o'zi oflaynda ochilishi

IndexedDB yolg'iz o'zi yetarli emas: navbatdagi sotuvlar saqlanadi, lekin internetsiz
sahifani qayta yuklasangiz bo'sh ekran chiqadi va kassir ularga yeta olmaydi. Shuning
uchun `vite-plugin-pwa` orqali service worker qo'shilgan — ilova qobig'i (HTML/JS/CSS)
keshlanadi va kassa internetsiz ham ochiladi.

`/api/*` **hech qachon** keshlanmaydi: keshlangan `sync/pull` kechagi qoldiqni bugungi
qilib ko'rsatardi, keshlangan POST javobi esa yozilmagan sotuvni "yozildi" deb bildirardi.

Dev rejimida ham yoqilgan (`devOptions.enabled`) — eng ko'p sinash kerak bo'lgan xususiyat
aynan hech kim sinamaydigani bo'lib qolmasligi uchun.

### Sinxronlash tartibi

Avval **push**, keyin **pull**. Navbatdagi ma'lumot boshqa hech qayerda yo'q;
katalogni esa istalgan vaqtda qayta yuklab olsa bo'ladi. Ulanish yarmida uzilsa,
albatta tugashi kerak bo'lgan yarmi — sotuvlarni ko'targani.

Ulanish qaytganda navbat avtomatik yuboriladi (`online` hodisasi).

## Qoldiq

Sotuv paytida lokal qoldiq **taxminan** kamaytiriladi (kassirga darrov to'g'ri raqam
ko'rinishi uchun). Server javobidagi haqiqiy qoldiq esa uni ustiga yozadi — shuning
uchun bir do'konda ikki kassa bir javonni sotsa ham raqamlar bir joyga keladi.

Qoldiq yetmasa sotuv **bloklanmaydi**, faqat ogohlantiriladi va minusga tushadi.
Tovar allaqachon xaridorga berilgan — sinxronlashda rad etish sotuvni yo'qotadi,
hech narsani qaytarmaydi.

`quantity: null` — "hech qachon inventarizatsiya qilinmagan", `0` emas. Shuning uchun
UI da `—` ko'rsatiladi: 0 deb ko'rsatish katalogning ko'p qismini "tugagan" qilib
belgilab qo'yardi.

## Fayllar

| Fayl | Vazifasi |
|---|---|
| `src/api.ts` | HTTP qatlami, token boshqaruvi, xato turlari |
| `src/db.ts` | Dexie (IndexedDB) sxemasi — kesh va outbox |
| `src/sync.ts` | Push/pull, navbat, kursor |
| `src/sales.ts` | Sotuvni yozish mantiqi |
| `src/screens/Login.tsx` | Kirish va kassani ulash |
| `src/screens/Pos.tsx` | Asosiy kassa ekrani |
| `src/screens/Checkout.tsx` | To'lov oynasi |
| `src/screens/Queue.tsx` | Navbat va tarix |

## API hujjati

Swagger: http://localhost:8083/api/documentation/pos

Backend kodi: `backend/routes/pos_api.php`, `backend/app/Services/Pos/`.
