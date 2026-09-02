# pDaftar POS backend

POS Integration API — **alohida Laravel ilovasi**, **bitta baza**.

pDaftar POS, AliPOS, YesPOS va boshqa kassalar shu API bilan gaplashadi.

```
Kassa (pos/)  ──►  pos_backend  :8090   ──┐
                                          ├──►  prod_pdaftar (bitta baza)
Mobil ilova   ──►  backend      :8083   ──┘
```

## Asosiy qoida — kod nusxalanmagan

`composer.json` dagi bitta qator hammasini belgilaydi:

```json
"App\\": "../backend/app/"
```

`App\` nomlar fazosi **nusxaga emas, `backend/app` ning o'ziga** qaraydi. Ya'ni POS
sotuvi pDaftarning **o'z** `StoreDebtUseCase`i orqali yoziladi — POS sotuvi va
ilova sotuvi bazada farq qilmaydi va bir-biridan uzoqlashib ketolmaydi.

Agar bu fayllar nusxalanganida, bitta bazaga ikki xil sotuv qoidasi yozardi va
pDaftar sotuv oqimini birinchi marta o'zgartirganda ikkisi jimgina farq qila
boshlardi.

**Buning narxi:** bu ilova `../backend` bo'lmasa ishlamaydi. Ikkalasi birga
deploy qilinadi.

`Pos\` nomlar fazosi — POS'ning o'z kodi (`app/`). U yerda 22 ta fayl:
controllerlar, servislar, `pos_terminals`/`pos_operations` modellari, middleware.

## Xavflar qanday yopilgan

Ajratishning barcha bog'liqliklari **jimgina** buziladi — shuning uchun har biri
tekshiriladi:

```bash
docker compose exec php-pos php artisan pos:health
curl http://localhost:8090/api/pos/v1/health      # 503 agar biror narsa buzilgan
```

| Tekshiruv | Nima uchun |
|---|---|
| `shared_domain` | `../backend` yo'q bo'lsa aniq aytadi, chalkash autoload xatosi emas |
| `sale_contract` | `StoreDebtUseCase` da `forceAllowNegativeStock` va DTO'da `getDate()` bormi. Kimdir refaktoring paytida olib tashlasa, sotuvlar ishlaydi-yu, **noto'g'ri** bo'lib qoladi |
| `observers` | **Eng muhimi.** `RepaymentObserver` ulanmasa sotuv yoziladi, lekin **Kassaga pul tushmaydi**. Hech qanday xato chiqmaydi |
| `database` | pDaftar bazasiga ulanganmi (bo'sh `shops` — boshqa bazaga ulangan belgisi) |
| `queue_prefix` | Redis prefiksi pDaftar bilan bir xilmi. Farq qilsa balans joblari pDaftar horizoni ko'rmaydigan navbatga tushadi va **mijoz balanslari yangilanmay qoladi** |
| `pos_tables` | `pos_terminals`, `pos_operations` bormi |

Deploy skriptida ishlatilsin — xato bo'lsa exit kodi 1:

```bash
git pull && php artisan pos:health && systemctl reload php-fpm
```

`pos:health` ni ishga tushirish `predis` paketi yo'qligini birinchi ishga
tushirishda aniqlab berdi — balans joblari navbatga tusha olmasdi. Aynan shuning
uchun bor.

## Ishga tushirish (lokal)

pDaftar backendi ishlab turishi kerak (`backend/` da `docker compose up -d`) —
MariaDB va Redis undan olinadi.

```bash
docker compose up -d
docker compose exec php-pos php artisan pos:health
```

| | |
|---|---|
| POS API | http://localhost:8090/api/pos/v1 |
| Health | http://localhost:8090/api/pos/v1/health |
| Swagger | http://localhost:8090/api/documentation/pos |

Kod o'zgargandan keyin — backenddagi bilan bir xil qoida:

```bash
docker compose restart php-pos          # opcache eski kodni ushlab turadi
docker compose exec php-pos composer dump-autoload -o   # yangi klass qo'shilsa
```

## Migratsiyalar

Bu ilova **faqat `pos_*` jadvallarini** biladi (`database/migrations/` da 2 ta
fayl). `migrations` jadvali umumiy — fayl nomlari bir xil bo'lgani uchun ikkinchi
marta ishga tushmaydi.

pDaftarning jadvallariga bu yerdan **hech qachon** migratsiya yozilmaydi.

## `.env`

`DB_*`, `REDIS_*` va **`APP_NAME`** `backend/.env` bilan aynan bir xil bo'lishi
shart. `APP_NAME` ham — Laravel Redis prefiksini undan hisoblaydi, prefiks farq
qilsa navbat ajralib ketadi. `POS_EXPECTED_REDIS_PREFIX` shu tekshiruv uchun.

## Nima yuklanmaydi

pDaftarning `AppServiceProvider`i **ataylab** ishga tushmaydi. U Filament,
Telescope, Horizon dashboard, Livewire, Firebase, SMS jadvallarini ko'taradi —
kassaga keraksiz, ba'zilari bu ilovada o'rnatilgan ham emas, va bittasi yiqilsa
POS ham yiqiladi.

O'rniga `PosServiceProvider` faqat sotuv yozish yo'liga kerak bo'lganini ulaydi:
uchta observer va Sanctum'ning maxsus token modeli. **Bu fayl eng xavfli joy** —
u nimani esdan chiqarsa, o'sha narsa jimgina ishlamay qoladi. Shuning uchun
`pos:health` observerlarni reflection bilan tekshiradi.

## Fayllar

| | |
|---|---|
| `app/Http/Controllers/` | POS endpointlari + `PosHealthController` |
| `app/Services/` | Sotuv, sinxronlash, idempotentlik, katalog, integrity |
| `app/Models/` | `PosTerminal`, `PosOperation` |
| `app/Providers/PosServiceProvider.php` | Observerlar + Sanctum |
| `routes/pos_api.php` | `/api/pos/v1/*` |
