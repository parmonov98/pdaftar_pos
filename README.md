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

## Kirish — kassa yo'q, sotuvchi bor

pDaftarda do'kon egasi sotuvchilarni taklif qiladi va har birining o'z raqami va
paroli bo'ladi. POS **aynan shuni** meros oladi:

- Anvar o'z raqami bilan kiradi va sotadi.
- Sobir o'z raqami bilan kiradi va sotadi.
- Hech kim "kassa yaratmaydi", tanlamaydi va nom bermaydi.

Oqim: raqam + parol → do'kon bitta bo'lsa avtomat tanlanadi (ko'p bo'lsa ro'yxat) →
sotuv ekrani. Tamom.

Fonda qurilma "handshake"i bo'lib o'tadi (`POST /terminals/register`) va u
sotuvchiga ko'rinmaydi. **Hech qanday limit yo'q** — do'konga kira oladigan har bir
user POS'da sotadi. Qurilma yozuvi ikki narsa uchun kerak: offline amal ID'lari
qurilma bo'yicha ajratiladi, va sotuv qaysi mashinada qilinganini bilish uchun.
Qurilma nomi o'zi qo'yiladi: `Anvar · Chrome`.

User tokeni saqlanmaydi — faqat handshake uchun ishlatiladi va tashlab yuboriladi.
Umumiy qurilmada owner tokenini saqlash xavfli.

Do'kon egasi `GET /terminals/manage` orqali qaysi qurilmalar ulanganini ko'radi va
keraksizini uzib qo'yadi.

## Sotuv ekrani

Filter tagida **faqat tanlangan mahsulotlar** turadi — ya'ni savdo o'zi.
Katalog "plitkalar devori" emas: mahsulot yozib qidiriladi yoki barcode skanerlanadi,
mosliklar qidiruv maydoni ustida dropdown bo'lib chiqadi. Skaner Enter bosganda
aniq moslik darrov qo'shiladi va maydon tozalanadi.

O'ng tomonda butun savdoga tegishli qarorlar: mijoz, valyuta, chegirma (% yoki summa),
jami va to'lov tugmasi.

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
