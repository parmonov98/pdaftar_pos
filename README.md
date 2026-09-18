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

Yangi klonda bitta buyruq yetarli — u yuqoridagi sibling'ni ham o'zi klon qiladi:

```bash
./scripts/setup.sh
```

Skript takror ishga tushirishga xavfsiz: har bir qadam avval tekshiradi.
Docker'siz, faqat bog'liqliklarni o'rnatish uchun `./scripts/setup.sh --no-docker`.

Qo'lda qilmoqchi bo'lsangiz:

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

## CI

`.github/workflows/ci.yml` har PR'da uch ishni bajaradi:

| Ish | Nima qiladi | pdaftar.backend kerakmi |
|---|---|---|
| `pos` | oxlint + `tsc` + vite build | yo'q |
| `pos_backend (pint)` | `pint --test app routes database` | yo'q |
| `pos_backend (phpunit)` | sibling'ni klon qilib, testlarni chopadi | **ha** |

Uchinchisi pdaftar.backend'ni o'qiy oladigan PAT'ni `PDAFTAR_BACKEND_TOKEN`
repo secret'idan oladi. Secret yo'q bo'lsa, ish **qizarmaydi — o'tkazib
yuboriladi**: fork'dan kelgan PR o'zida bo'lishi mumkin bo'lmagan secret uchun
qizarmasligi kerak.

Testlar `PosIntegrityService`ning CI javob bera oladigan ikki tekshiruvini
qat'iy talab qiladi: `shared_domain` va `observers`. Qolgan to'rttasi tirik
pDaftar bazasi va Redis'ini talab qiladi, shuning uchun to'liq `pos:health`
alohida, **majburiy bo'lmagan** qadam sifatida chiqadi.

## Claude Code on the web

`.claude/hooks/session-start.sh` veb-sessiya konteynerini tayyorlaydi:
pdaftar.backend'ni yonma-yon klon qiladi, `composer install` va `npm install`
qiladi, so'ng `pos:health`ni chop etadi.

Sessiya muhiti manbalariga **`parmonov98/pdaftar.backend` ham qo'shilgan
bo'lishi kerak** — aks holda klon qadamida ogohlantirish chiqadi va POS backend
ishga tushmaydi (Kassa ilovasi esa ishlayveradi).
