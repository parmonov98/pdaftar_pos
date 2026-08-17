<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

/**
 * OpenAPI root for the POS Integration API.
 *
 * This class holds no logic — it exists so the document's Info, servers,
 * security scheme and shared schemas live in one place instead of being
 * scattered across whichever controller happened to be written first.
 *
 * @OA\Info(
 *     title="pDaftar POS Integration API",
 *     version="1.0.0",
 *     description="
 * Integratsiya API — pDaftar POS va uchinchi tomon kassalari (AliPOS, YesPOS va boshqalar) uchun.
 *
 * ## Asosiy qoida
 * pDaftar — **haqiqat manbai** (source of truth). Kassaning lokal bazasi faqat **kesh + navbat**.
 * Qoldiq har doim `stock_movements` ledgeridan hisoblanadi, kassa uni faqat ko'rsatadi.
 *
 * ## Offline ishlash
 * Har bir yozuv amali `client_operation_id` (UUID, kassada amal bajarilgan payt yaratiladi) bilan
 * yuboriladi. Takroriy yuborish hech narsani o'zgartirmaydi — server saqlangan javobni qaytaradi
 * (`replayed: true`). Internet paydo bo'lganda navbat `POST /sync/push` orqali paket qilib yuboriladi.
 *
 * ## Sotuv va qoldiq
 * Kassadan kelgan sotuv **rad etilmaydi**: tovar allaqachon xaridorga berilgan. Qoldiq yetmasa
 * minusga tushadi va bu ko'rinadigan muammo bo'lib qoladi — sinxronlashda yo'qolgan sotuvdan ko'ra yaxshiroq.
 *
 * ## Uchinchi tomon kassalari
 * Tashqi POS tokeni `pos:catalog.read`, `pos:sales.write`, `pos:clients.write` scope'lari bilan
 * beriladi — ular o'z omborini o'zi yuritadi, bizga faqat sotuvni yuboradi.
 * ",
 *
 *     @OA\Contact(name="pDaftar", url="https://pdaftar.uz")
 * )
 *
 * @OA\Server(url="http://localhost:8083", description="Lokal (Docker)")
 * @OA\Server(url="https://api.pdaftar2.uz", description="Production")
 *
 * @OA\SecurityScheme(
 *     securityScheme="posToken",
 *     type="http",
 *     scheme="bearer",
 *     description="Kassa tokeni — /terminals/register javobidan olinadi. Ro'yxatdan o'tish uchun oddiy pDaftar user tokeni ishlatiladi."
 * )
 *
 * @OA\Tag(name="POS Terminal", description="Kassani ro'yxatdan o'tkazish va boshqarish")
 * @OA\Tag(name="POS Catalog", description="Katalogni yuklab olish (mahsulot, mijoz, ta'minotchi)")
 * @OA\Tag(name="POS Sales", description="Sotuv va bekor qilish")
 * @OA\Tag(name="POS Write", description="Mahsulot, mijoz, ta'minotchi, ombor, kassa amallari")
 * @OA\Tag(name="POS Sync", description="Offline navbatni sinxronlash")
 *
 * @OA\Schema(
 *     schema="PosOperationResult",
 *     title="Amal natijasi",
 *
 *     @OA\Property(property="client_operation_id", type="string", format="uuid"),
 *     @OA\Property(property="type", type="string", example="sale.create"),
 *     @OA\Property(property="status", type="string", enum={"applied","failed"}),
 *     @OA\Property(
 *         property="replayed",
 *         type="boolean",
 *         description="true — bu amal avval bajarilgan, hech narsa o'zgarmadi. Kassa uni navbatdan o'chiradi."
 *     ),
 *     @OA\Property(property="data", type="object"),
 *     @OA\Property(property="error", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="PosSaleRequest",
 *     title="Sotuv",
 *     required={"client_operation_id","currency_id","items"},
 *
 *     @OA\Property(property="client_operation_id", type="string", format="uuid", description="Kassada amal bajarilgan paytda yaratiladi, yuborishda emas"),
 *     @OA\Property(property="occurred_at", type="string", format="date-time", description="Kassada sotuv bo'lgan vaqt (offline bo'lsa o'tmish)"),
 *     @OA\Property(property="currency_id", type="integer", example=1),
 *     @OA\Property(property="client_id", type="integer", nullable=true, description="null va to'liq to'langan bo'lsa — 'Naqd xaridor' uy hisobiga yoziladi. Nasiya uchun majburiy."),
 *     @OA\Property(
 *         property="client",
 *         type="object",
 *         nullable=true,
 *         description="Yangi mijozni shu yerda yaratish",
 *         @OA\Property(property="name", type="string"),
 *         @OA\Property(property="phone_number", type="string", nullable=true)
 *     ),
 *     @OA\Property(property="payment_type", type="string", enum={"cash","card","terminal","bank_account"}, nullable=true, description="null = nasiya"),
 *     @OA\Property(property="paid_amount", type="number", format="float", nullable=true),
 *     @OA\Property(property="discount_amount", type="number", format="float", default=0),
 *     @OA\Property(property="note", type="string", nullable=true),
 *     @OA\Property(property="receipt_no", type="string", nullable=true),
 *     @OA\Property(
 *         property="items",
 *         type="array",
 *
 *         @OA\Items(
 *             required={"product_id","quantity"},
 *
 *             @OA\Property(property="product_id", type="integer"),
 *             @OA\Property(property="quantity", type="number", format="float"),
 *             @OA\Property(property="price", type="number", format="float", nullable=true, description="Kassada o'zgartirilgan narx. Yuborilmasa katalog narxi olinadi.")
 *         )
 *     )
 * )
 */
class PosApiDocs {}
