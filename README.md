# pDaftar POS

Kassa tizimi — backend va frontend bitta repoda.

```
pdaftar_pos/
  pos_backend/   POS Integration API (Laravel)   → :8090
  pos/           Kassa ilovasi (React, offline)  → :5174
```

## ⚠️ Bu repo yolg'iz ishlamaydi

`pos_backend` pDaftarning **o'z domen kodini** ishlatadi — nusxasini emas.
`composer.json` da:

```json
"App\\": "../../backend/app/"
```

Ya'ni POS sotuvi pDaftarning **o'z** `StoreDebtUseCase`i orqali yoziladi. Shuning
uchun POS sotuvi va ilova sotuvi bazada farq qilmaydi va bir-biridan uzoqlashib
keta olmaydi. Agar bu fayllar nusxalanganida, bitta bazaga ikki xil sotuv qoidasi
yozardi va pDaftar sotuv oqimini birinchi marta o'zgartirganda ikkisi **jimgina**
farq qila boshlardi.

Buning narxi: **`pdaftar.backend` shu repo yonida turishi shart.**

```
parent/
  backend/       ← git clone git@github.com:parmonov98/pdaftar.backend.git backend
  pdaftar_pos/   ← shu repo
```

Tekshirish:

```bash
cd pos_backend
docker compose exec php-pos php artisan pos:health
```

Yo'q bo'lsa aniq aytadi: `Topilmadi: App\Models\Debt … pdaftar.backend shu repo
yonida (sibling) turibdimi?`

## Ishga tushirish

```bash
# 1. pDaftar backendi (baza va Redis shundan)
cd ../backend && docker compose up -d

# 2. POS backendi
cd ../pdaftar_pos/pos_backend && docker compose up -d
docker compose exec php-pos php artisan pos:health

# 3. Kassa ilovasi
cd ../pos && npm install && npm run dev
```

| | |
|---|---|
| Kassa | http://localhost:5174 |
| POS API | http://localhost:8090/api/pos/v1 |
| Health | http://localhost:8090/api/pos/v1/health |
| Swagger | http://localhost:8090/api/documentation/pos |

## Asosiy g'oyalar

**Bitta baza.** POS pDaftarning bazasiga yozadi. O'z buxgalteriyasi yo'q.

**Naqd sotuv → Kassa, nasiya → qarz.** pDaftarning Sotuvi optomchilar kalkulyatori:
naqd bo'lsa saqlanmaydi, saqlangani nasiya. POS'da oddiy sotuv ham bor, u
`shop_incomes`ga kirim bo'lib tushadi. Nasiya esa `debts`ga — pDaftarning o'z
`StoreDebtUseCase`i orqali.

**Oflayn.** Har bir yozuv avval brauzer bazasidagi navbatga tushadi, keyin
yuboriladi — internet bor bo'lsa ham. Har amalda `client_operation_id` (UUID)
bo'ladi, shuning uchun takroriy yuborish ikkinchi sotuv yaratmaydi.

**Qoldiq.** Har doim serverdagi `stock_movements` ledgeridan. Kassa uni faqat
ko'rsatadi. Qoldiq yetmasa sotuv **bloklanmaydi** — tovar allaqachon berilgan.

Batafsil: [`pos_backend/README.md`](pos_backend/README.md) va [`pos/README.md`](pos/README.md).

## Deploy

Ikkalasi **birga** chiqariladi — umumiy domen o'zgarsa POS ham u bilan yangilanishi
kerak. Deploy skriptida:

```bash
git pull && php artisan pos:health && systemctl reload php-fpm
```

`pos:health` xato bo'lsa exit kodi 1 qaytaradi, shuning uchun reliz to'xtaydi.
