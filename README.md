# pDaftar POS

Kassa tizimi — backend va frontend bitta repoda.

```
pdaftar_pos/
  pos_backend/   POS API (Laravel)              → :8090
  pos/           Kassa ilovasi (React, offline) → :5174
```

## Mustaqil mahsulot

POS o'z serverida, o'z bazasida, o'z domenida ishlaydi. pDaftar bilan
integratsiya **API orqali** bo'ladi — kod darajasida hech qanday bog'liqlik yo'q.

Ilgari bunday emas edi: `composer.json` `App\` ni `../../backend/app/` ga
ulardi, ya'ni POS sotuvni pDaftarning **o'z** `StoreDebtUseCase`i orqali
yozardi. Buning uchun `pdaftar.backend` shu repo yonida turishi **shart** edi.
Endi shart emas — klon qiling va ishga tushiring.

```bash
./scripts/setup.sh
```

Tekshirish:

```bash
cd pos_backend
docker compose exec php-pos php artisan pos:health
```

## Ishga tushirish

```bash
cd pos_backend && docker compose up -d     # php, nginx, mariadb, redis
docker compose exec -T php-pos php artisan migrate
cd ../pos && npm install && npm run dev
```

| | |
|---|---|
| Kassa | http://localhost:5174 |
| API | http://localhost:8090/api/pos/v1 |
| Health | http://localhost:8090/api/pos/v1/health |

## Hisob ochish

POS o'z foydalanuvchilariga ega. pDaftar hisobi kerak emas:

```bash
curl -X POST http://localhost:8090/api/pos/v1/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"name":"Anvar","phone_number":"+998901234567","password":"kassa12345","shop_name":"Anvar Market"}'
```

Ro'yxatdan o'tish bitta qadamda foydalanuvchi va uning birinchi do'konini
yaratadi — do'koni yo'q hisob kassa ocha olmaydi, ya'ni umuman hech nima qila
olmaydi.

## Nima bor, nima yo'q

Ishlaydi:

- ro'yxatdan o'tish, kirish, chiqish (`/auth/*`)
- kassa (terminal) ro'yxatdan o'tkazish va boshqarish (`/terminals/*`)
- health (`/health`)

Hali yo'q — **sotuv oqimi**. `/sales`, `/sync/pull`, `/sync/push`, `/products`,
`/clients`, `/deliveries`, `/stock`, `/cash` route'lari olib tashlandi. Ular
pDaftarning domen kodi ustidagi yupqa qatlam edi: sotuvni `StoreDebtUseCase`
yozardi, qoldiqni `StockService` ko'chirardi, pulni `DebtObserver` Kassaga
o'tkazardi. POS o'z bazasida ishlaganda bu klasslar mavjud bo'lmagan
jadvallarni o'qiydi.

Ular 500 qaytarib turgandan ko'ra o'chirildi: mavjud, lekin yiqiladigan route
klientni qayta urinishga o'rgatadi; yo'q route esa rostini aytadi. Har biri
POSning **o'z** mahsulot, mijoz va sotuv modeli ustida qaytadi.

## Deploy

`main` ga push → GitHub Actions → staging server.

| | |
|---|---|
| Server | `pos-staging` (Linode, eu-central, 2GB) |
| Domen | https://pos.pdaftar.uz |
| Workflow | `.github/workflows/deploy-staging.yml` |

Kassa cloud runner'da yig'iladi (2GB serverda `vite build` OOM bo'ladi), keyin
`dist/` rsync qilinadi va `scripts/deploy-staging.sh` ishga tushadi.

Yangi serverni noldan tayyorlash:

```bash
./scripts/provision-staging.sh pos.pdaftar.uz
```

Swap, Docker, kod, `.env`, konteynerlar, migratsiya, nginx va TLS — hammasi.
Qayta ishga tushirish xavfsiz.

## Testlar

```bash
cd pos_backend && ./vendor/bin/phpunit
cd pos && npm run lint && npm run build
```
