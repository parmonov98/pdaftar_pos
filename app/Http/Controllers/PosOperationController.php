<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pos\Models\PosTerminal;
use Pos\Services\PosOperationDispatcher;
use Pos\Services\PosOperationResult;

/**
 * The single-operation REST surface.
 *
 * Every method here is the same three lines: take the request body, wrap it in
 * an operation envelope, hand it to the dispatcher. The handlers, validation,
 * idempotency and scope checks all live in PosOperationDispatcher, which is
 * also what /sync/push calls — so an online write and the same write replayed
 * from an outbox cannot drift apart.
 *
 * These endpoints exist for tills that are online and want an immediate answer
 * (and for third-party systems that would rather POST one sale than learn a
 * batch protocol). They are not a second implementation.
 */
class PosOperationController extends Controller {
    public function __construct(private readonly PosOperationDispatcher $dispatcher) {}

    /**
     * @OA\Post(
     *     path="/api/pos/v1/sales",
     *     summary="Sotuv qilish",
     *     description="
     * pDaftardagi Sotuv bilan bir xil yozuvlarni yaratadi: frozen SalesCalcList + qatorlar → Debt →
     * `stock_movements` (sale) → to'langan bo'lsa Repayment → Kassa Kirim. Ya'ni POS sotuvi va ilova
     * sotuvi bazada bir xil ko'rinadi.
     *
     * Qoldiq yetmasa ham **rad etilmaydi** — minusga tushadi.
     * ",
     *     tags={"POS Sales"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/PosSaleRequest")),
     *
     *     @OA\Response(response=201, description="Sotuv yozildi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult")),
     *     @OA\Response(response=200, description="Takroriy yuborish — avvalgi javob qaytarildi (replayed=true)"),
     *     @OA\Response(response=403, description="Scope yetarli emas"),
     *     @OA\Response(response=409, description="client_operation_id boshqa ma'lumot bilan ishlatilgan"),
     *     @OA\Response(response=422, description="Validatsiya xatosi")
     * )
     */
    public function sale(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_SALE_CREATE);
    }

    /**
     * @OA\Post(
     *     path="/api/pos/v1/sales/cancel",
     *     summary="Sotuvni bekor qilish",
     *     description="Qoldiqni tiklaydi — sotuv harakatlari ledgerdan o'chiriladi, kompensatsiya yozuvi yozilmaydi, shuning uchun mahsulot tarixi 'sotuv bo'lmagan' ko'rinishida qoladi.",
     *     tags={"POS Sales"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","sale_id"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="sale_id", type="integer")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Bekor qilindi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function cancelSale(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_SALE_CANCEL);
    }

    /**
     * @OA\Post(
     *     path="/api/pos/v1/products",
     *     summary="Mahsulot yaratish",
     *     description="Barcode bo'yicha mavjud mahsulot topilsa — yangi qator yaratilmaydi, mavjudi yangilanadi (qoldiq ikkiga bo'linib ketmasligi uchun). `quantity` yuborilsa `opening` harakati sifatida yoziladi.",
     *     tags={"POS Write"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","name"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="local_id", type="string", nullable=true, description="Offline yaratilgan ID. Bir paket ichida keyingi amallar bu mahsulotga local-havola obyekti orqali murojaat qiladi — /sync/push tavsifiga qarang."),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="barcode", type="string", nullable=true),
     *             @OA\Property(property="price", type="number", format="float", nullable=true),
     *             @OA\Property(property="quantity", type="number", format="float", nullable=true),
     *             @OA\Property(property="unit_id", type="integer", nullable=true),
     *             @OA\Property(property="currency_id", type="integer", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Yaratildi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function createProduct(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_PRODUCT_CREATE);
    }

    /**
     * @OA\Patch(
     *     path="/api/pos/v1/products",
     *     summary="Mahsulotni tahrirlash",
     *     description="Yuborilmagan maydonlar tegilmaydi (array_key_exists), aniq `null` esa tozalaydi.",
     *     tags={"POS Write"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","id"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="price", type="number", format="float"),
     *             @OA\Property(property="barcode", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Yangilandi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function updateProduct(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_PRODUCT_UPDATE);
    }

    /**
     * @OA\Post(
     *     path="/api/pos/v1/clients",
     *     summary="Mijoz qo'shish",
     *     tags={"POS Write"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","name"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="phone_number", type="string", nullable=true),
     *             @OA\Property(property="address", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Yaratildi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function createClient(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_CLIENT_CREATE);
    }

    /**
     * @OA\Post(
     *     path="/api/pos/v1/suppliers",
     *     summary="Ta'minotchi yaratish",
     *     tags={"POS Write"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","name"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="phone_number", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Yaratildi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function createSupplier(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_SUPPLIER_CREATE);
    }

    /**
     * @OA\Post(
     *     path="/api/pos/v1/suppliers/transactions",
     *     summary="Ta'minotchiga kirim/chiqim",
     *     tags={"POS Write"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","supplier_id","amount"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="supplier_id", type="integer"),
     *             @OA\Property(property="direction", type="string", enum={"chiqim","kirim"}, default="chiqim"),
     *             @OA\Property(property="amount", type="number", format="float"),
     *             @OA\Property(property="currency_id", type="integer", nullable=true),
     *             @OA\Property(property="description", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Yozildi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function supplierTransaction(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_SUPPLIER_TRANSACTION);
    }

    /**
     * @OA\Post(
     *     path="/api/pos/v1/deliveries",
     *     summary="Prixod — ta'minotchidan mahsulot kirimi",
     *     description="Nakladnoy yaratadi, mahsulotlarni topadi/yaratadi va `purchase` harakatlarini yozadi.",
     *     tags={"POS Write"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","currency_id","products"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="supplier_id", type="integer", nullable=true),
     *             @OA\Property(property="supplier_name", type="string", nullable=true, description="supplier_id bo'lmasa — nomi bo'yicha topiladi/yaratiladi"),
     *             @OA\Property(property="currency_id", type="integer"),
     *             @OA\Property(property="total_amount", type="number", format="float"),
     *             @OA\Property(
     *                 property="products",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     required={"name","quantity","price","unit_id"},
     *
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="quantity", type="number", format="float"),
     *                     @OA\Property(property="price", type="number", format="float", description="Tan narx"),
     *                     @OA\Property(property="sale_price", type="number", format="float", nullable=true, description="Sotuv narxi"),
     *                     @OA\Property(property="unit_id", type="integer")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Kirim yozildi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function createDelivery(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_DELIVERY_CREATE);
    }

    /**
     * @OA\Post(
     *     path="/api/pos/v1/stock/movements",
     *     summary="Ombor harakati — tuzatish, qaytarish, hisobdan chiqarish, inventarizatsiya",
     *     description="`counted_quantity` yuborilsa — inventarizatsiya (farq yoziladi). Aks holda `type` + `quantity`. Ishorani tur belgilaydi, mijoz emas.",
     *     tags={"POS Write"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","product_id"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="product_id", type="integer"),
     *             @OA\Property(property="type", type="string", enum={"adjustment","return","write_off"}, nullable=true),
     *             @OA\Property(property="quantity", type="number", format="float", nullable=true),
     *             @OA\Property(property="counted_quantity", type="number", format="float", nullable=true),
     *             @OA\Property(property="note", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Yozildi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function stockMovement(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_STOCK_MOVEMENT);
    }

    /**
     * @OA\Post(
     *     path="/api/pos/v1/cash/income",
     *     summary="Kassa kirim",
     *     tags={"POS Write"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","amount"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="amount", type="number", format="float"),
     *             @OA\Property(property="currency_id", type="integer", nullable=true),
     *             @OA\Property(property="category_id", type="integer", nullable=true),
     *             @OA\Property(property="payment_type", type="string", enum={"cash","card","terminal","bank_account"}, default="cash"),
     *             @OA\Property(property="description", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Yozildi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function cashIncome(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_CASH_INCOME);
    }

    /**
     * @OA\Post(
     *     path="/api/pos/v1/cash/expense",
     *     summary="Kassa chiqim",
     *     tags={"POS Write"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_operation_id","amount"},
     *
     *             @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *             @OA\Property(property="amount", type="number", format="float"),
     *             @OA\Property(property="currency_id", type="integer", nullable=true),
     *             @OA\Property(property="category_id", type="integer", nullable=true),
     *             @OA\Property(property="payment_type", type="string", enum={"cash","card","terminal","bank_account"}, default="cash"),
     *             @OA\Property(property="description", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Yozildi", @OA\JsonContent(ref="#/components/schemas/PosOperationResult"))
     * )
     */
    public function cashExpense(Request $request): JsonResponse {
        return $this->run($request, PosOperationDispatcher::TYPE_CASH_EXPENSE);
    }

    /**
     * The whole controller, once.
     *
     * The request body IS the payload — `client_operation_id` and `occurred_at`
     * are lifted out of it as envelope fields rather than being demanded in a
     * nested object, because a third party POSTing one sale should not have to
     * learn the batch envelope to do it.
     */
    private function run(Request $request, string $type): JsonResponse {
        /** @var PosTerminal $terminal */
        $terminal = $request->attributes->get('pos_terminal');
        /** @var User $user */
        $user = $request->user();

        $body = $request->all();

        $envelope = [
            'client_operation_id' => $body['client_operation_id'] ?? null,
            'type' => $type,
            'occurred_at' => $body['occurred_at'] ?? null,
            'payload' => $this->payloadOf($body),
        ];

        $map = [];
        $result = $this->dispatcher->dispatch($terminal, $user, $envelope, $map);

        return $this->respond($result);
    }

    private function respond(PosOperationResult $result): JsonResponse {
        return response()->json([
            'success' => $result->status === 'applied',
            'data' => $result->toArray(),
        ], $result->httpStatus);
    }

    /**
     * Strip the envelope keys so they are not also hashed as payload —
     * otherwise a client re-sending the same operation with a refreshed
     * `occurred_at` would change the hash, and every retry would 409 instead
     * of replaying.
     */
    private function payloadOf(array $body): array {
        unset($body['client_operation_id'], $body['occurred_at'], $body['type']);

        return $body;
    }
}
