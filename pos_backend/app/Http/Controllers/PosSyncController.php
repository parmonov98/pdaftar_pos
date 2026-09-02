<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Pos\Models\PosOperation;
use Pos\Models\PosTerminal;
use Pos\Services\PosCatalogService;
use Pos\Services\PosOperationDispatcher;

/**
 * The "Sinxronlash" button, both directions.
 *
 * PUSH sends the till's outbox up. PULL brings the catalogue down. They are
 * separate calls on purpose: a till with a full outbox and no network budget
 * for a full catalogue refresh must still be able to get its sales off the
 * device, and pushing is the half that has data nobody else has.
 */
class PosSyncController extends Controller {
    /** One batch's ceiling. See the push() docblock for why it is not higher. */
    private const MAX_BATCH = 200;

    public function __construct(
        private readonly PosOperationDispatcher $dispatcher,
        private readonly PosCatalogService $catalog,
    ) {}

    /**
     * @OA\Post(
     *     path="/api/pos/v1/sync/push",
     *     summary="Offline navbatni serverga yuborish",
     *     description="
     * Kassadagi bajarilmagan amallar paketi. Har biri alohida baholanadi — bittasi xato bo'lsa
     * qolganlari baribir yoziladi, chunki bir yomon qatordan butun kun savdosi to'xtab qolmasligi kerak.
     *
     * Javobdagi har bir element `status` va `replayed` maydonlariga ega. Kassa `applied` bo'lganini
     * (`replayed` bo'lsa ham) navbatdan o'chiradi, `failed` bo'lganini kassirga ko'rsatadi.
     *
     * Amallar **ketma-ket** bajariladi, chunki bitta paket ichida yaratilgan mahsulotga keyingi
     * amal murojaat qilishi mumkin. Buning uchun `product.create` payloadida `local_id` yuboriladi,
     * keyingi amalda esa id o'rniga bitta kalitli obyekt beriladi: kalit nomi — dollar belgisi va
     * `local` so'zi, qiymati — o'sha `local_id`. Server uni yangi yaratilgan ID ga almashtiradi.
     * ",
     *     tags={"POS Sync"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"operations"},
     *
     *             @OA\Property(
     *                 property="operations",
     *                 type="array",
     *                 maxItems=200,
     *
     *                 @OA\Items(
     *                     required={"client_operation_id","type","payload"},
     *
     *                     @OA\Property(property="client_operation_id", type="string", format="uuid"),
     *                     @OA\Property(property="type", type="string", enum={"sale.create","sale.cancel","product.create","product.update","client.create","supplier.create","supplier.transaction","delivery.create","stock.movement","cash.income","cash.expense"}),
     *                     @OA\Property(property="occurred_at", type="string", format="date-time"),
     *                     @OA\Property(property="payload", type="object")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Har bir amal natijasi",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="results", type="array", @OA\Items(ref="#/components/schemas/PosOperationResult")),
     *                 @OA\Property(property="applied", type="integer"),
     *                 @OA\Property(property="failed", type="integer")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Paket juda katta yoki noto'g'ri")
     * )
     */
    public function push(Request $request): JsonResponse {
        /** @var PosTerminal $terminal */
        $terminal = $request->attributes->get('pos_terminal');
        /** @var User $user */
        $user = $request->user();

        $operations = $request->input('operations');

        if (! is_array($operations)) {
            return response()->json([
                'success' => false,
                'message' => '`operations` massiv bo\'lishi kerak',
            ], 422);
        }

        if (count($operations) > self::MAX_BATCH) {
            // Bounded so one request cannot hold a database connection for
            // minutes: a till with 2 000 queued sales sends ten batches and
            // gets ten chances to keep progress, instead of one request that
            // times out at operation 1 900 and starts over.
            return response()->json([
                'success' => false,
                'message' => 'Bir paketda ko\'pi bilan '.self::MAX_BATCH.' ta amal yuboring',
                'code' => 'batch_too_large',
                'max_batch' => self::MAX_BATCH,
            ], 422);
        }

        $results = [];
        $applied = 0;
        $failed = 0;

        // Shared across the batch so an operation can reference something an
        // earlier one in the SAME batch created — the offline "add a product,
        // then sell it" sequence has no server id for that product yet.
        $localIdMap = [];

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                $failed++;

                continue;
            }

            $result = $this->dispatcher->dispatch($terminal, $user, $operation, $localIdMap);

            $results[] = $result->toArray();
            $result->status === 'applied' ? $applied++ : $failed++;
        }

        PosTerminal::whereKey($terminal->id)->update(['last_sync_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => [
                'results' => $results,
                'applied' => $applied,
                'failed' => $failed,
                'local_ids' => $localIdMap,
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/pos/v1/sync/pull",
     *     summary="Katalogni yuklab olish (inkremental)",
     *     description="
     * `since` + `since_id` kursori bo'yicha o'zgarganlarini qaytaradi. `has_more: true` bo'lsa
     * javobdagi `next_since`/`next_since_id` bilan qayta chaqiring.
     *
     * O'chirilgan mahsulot/mijoz `deleted: true` bilan keladi — kassa uni lokal bazadan o'chiradi.
     * Birinchi sinxronlashda `since` yubormang.
     * ",
     *     tags={"POS Catalog"},
     *     security={{"posToken":{}}},
     *
     *     @OA\Parameter(name="since", in="query", @OA\Schema(type="string", format="date-time")),
     *     @OA\Parameter(name="since_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(
     *         name="entities",
     *         in="query",
     *         description="Vergul bilan: products,clients,suppliers,units,currencies,income_categories,expense_categories",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=500, maximum=1000)),
     *
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function pull(Request $request): JsonResponse {
        /** @var PosTerminal $terminal */
        $terminal = $request->attributes->get('pos_terminal');

        $since = $this->parseDate($request->query('since'));
        $sinceId = $request->query('since_id') !== null ? (int) $request->query('since_id') : null;

        $entities = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('entities', ''))
        )));

        $payload = $this->catalog->pull(
            $terminal->shop,
            $entities,
            $since,
            $sinceId,
            (int) $request->query('limit', '500'),
        );

        return response()->json(['success' => true] + $payload);
    }

    /**
     * @OA\Get(
     *     path="/api/pos/v1/sync/status",
     *     summary="Sinxronlash holati",
     *     description="Kassa oxirgi marta qachon sinxronlangani va so'nggi amallar. Nosozlikni tekshirish uchun.",
     *     tags={"POS Sync"},
     *     security={{"posToken":{}}},
     *
     *     @OA\Parameter(name="failed_only", in="query", @OA\Schema(type="boolean")),
     *
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function status(Request $request): JsonResponse {
        /** @var PosTerminal $terminal */
        $terminal = $request->attributes->get('pos_terminal');

        $recent = PosOperation::query()
            ->where('pos_terminal_id', $terminal->id)
            ->when(
                $request->boolean('failed_only'),
                fn ($q) => $q->whereIn('status', [PosOperation::STATUS_FAILED, PosOperation::STATUS_ERROR])
            )
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'last_sync_at' => $terminal->last_sync_at?->toIso8601String(),
                'last_seen_at' => $terminal->last_seen_at?->toIso8601String(),
                'server_time' => now()->toIso8601String(),
                'counts' => [
                    'applied' => PosOperation::where('pos_terminal_id', $terminal->id)
                        ->where('status', PosOperation::STATUS_APPLIED)->count(),
                    // Refused before writing — the till may resend these.
                    'failed' => PosOperation::where('pos_terminal_id', $terminal->id)
                        ->where('status', PosOperation::STATUS_FAILED)->count(),
                    // Unknown state — must NOT be resent blindly. A non-zero
                    // count here is what someone should be looking at.
                    'error' => PosOperation::where('pos_terminal_id', $terminal->id)
                        ->where('status', PosOperation::STATUS_ERROR)->count(),
                ],
                'operations' => $recent->map(fn (PosOperation $o) => [
                    'client_operation_id' => $o->client_operation_id,
                    'type' => $o->type,
                    'status' => $o->status,
                    'error' => $o->error,
                    'entity_id' => $o->entity_id,
                    'occurred_at' => $o->occurred_at?->toIso8601String(),
                    'created_at' => $o->created_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/pos/v1/products/lookup",
     *     summary="Barcode yoki kod bo'yicha mahsulot topish",
     *     description="Skaner uchun. Avval `barcode`, keyin ichki `code` bo'yicha qidiriladi.",
     *     tags={"POS Catalog"},
     *     security={{"posToken":{}}},
     *
     *     @OA\Parameter(name="code", in="query", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Topildi"),
     *     @OA\Response(response=404, description="Topilmadi")
     * )
     */
    public function lookup(Request $request): JsonResponse {
        /** @var PosTerminal $terminal */
        $terminal = $request->attributes->get('pos_terminal');

        $product = $this->catalog->lookup($terminal->shop, (string) $request->query('code', ''));

        if ($product === null) {
            return response()->json([
                'success' => false,
                'message' => 'Bunday mahsulot topilmadi',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $product]);
    }

    private function parseDate(mixed $value): ?Carbon {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
