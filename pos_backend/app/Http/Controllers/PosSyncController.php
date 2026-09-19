<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Pos\Models\PosOperation;
use Pos\Services\PosCatalogService;
use Pos\Services\PosIdempotencyService;
use Pos\Services\PosOperationDispatcher;

/**
 * The offline till's two halves: what it downloads, and what it sends back.
 */
class PosSyncController extends Controller
{
    public function __construct(
        private readonly PosCatalogService $catalog,
        private readonly PosIdempotencyService $idempotency,
        private readonly PosOperationDispatcher $dispatcher,
    ) {}

    /** Everything changed since the till's cursor. */
    public function pull(Request $request): JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');

        $data = $request->validate([
            'since' => ['nullable', 'date'],
            'since_id' => ['nullable', 'integer'],
            'entities' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $entities = isset($data['entities'])
            ? array_filter(array_map('trim', explode(',', $data['entities'])))
            : [];

        return response()->json([
            'data' => $this->catalog->pull(
                $terminal->shop,
                $entities,
                isset($data['since']) ? Carbon::parse($data['since']) : null,
                $data['since_id'] ?? null,
                (int) ($data['limit'] ?? 500),
            ),
        ]);
    }

    /** One product, by barcode or code. The scanner's endpoint. */
    public function lookup(Request $request): JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');
        $code = trim((string) $request->query('code', ''));

        if ($code === '') {
            return response()->json(['message' => 'Kod kerak'], 422);
        }

        $product = $this->catalog->lookup($terminal->shop, $code);

        if ($product === null) {
            return response()->json([
                'message' => 'Mahsulot topilmadi',
                'code' => 'product_not_found',
            ], 404);
        }

        return response()->json(['data' => $product]);
    }

    /**
     * A batch from the outbox.
     *
     * Always 200 at the envelope level, with a per-operation verdict inside.
     * One bad line out of forty must not reject the other thirty-nine — the
     * till would then have no way to make progress except to send them all
     * again, forever.
     */
    public function push(Request $request): JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');
        $token = $request->user()?->currentAccessToken();
        $userId = (int) $terminal->user_id;

        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:200'],
            'operations.*.client_operation_id' => ['required', 'uuid'],
            'operations.*.type' => ['required', 'string', 'max:48'],
            'operations.*.occurred_at' => ['nullable', 'date'],
            'operations.*.payload' => ['required', 'array'],
        ]);

        $results = [];
        $applied = 0;
        $failed = 0;
        $localIds = [];

        foreach ($data['operations'] as $op) {
            $occurredAt = isset($op['occurred_at']) ? Carbon::parse($op['occurred_at']) : null;

            $result = $this->idempotency->run(
                $terminal,
                $op['client_operation_id'],
                $op['type'],
                $op['payload'],
                $occurredAt,
                fn () => $this->dispatcher->dispatch(
                    $terminal, $op['type'], $op['payload'], $occurredAt, $userId, $token,
                ),
            );

            $results[] = $result->toArray();
            $result->status === 'applied' ? $applied++ : $failed++;

            // The till files its rows under its own ids; this is how it learns
            // what the server called them.
            $entityId = $result->data['sale']['id'] ?? $result->data['product']['id'] ?? null;
            if ($entityId !== null) {
                $localIds[$op['client_operation_id']] = (int) $entityId;
            }
        }

        return response()->json([
            'data' => [
                'results' => $results,
                'applied' => $applied,
                'failed' => $failed,
                'local_ids' => $localIds,
            ],
        ]);
    }

    /**
     * What this terminal has sent and how it went.
     *
     * The `error` rows are the ones that matter: an operation that blew up in
     * a way the server could not prove left the database untouched is never
     * retried automatically, so somebody has to be able to see it.
     */
    public function status(Request $request): JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');

        $operations = PosOperation::query()
            ->where('pos_terminal_id', $terminal->id)
            ->latest('id')
            ->limit(50)
            ->get(['client_operation_id', 'type', 'status', 'error']);

        $counts = PosOperation::query()
            ->where('pos_terminal_id', $terminal->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'last_sync_at' => $terminal->last_sync_at?->toIso8601String(),
                'counts' => [
                    'applied' => (int) ($counts['applied'] ?? 0),
                    'failed' => (int) ($counts['failed'] ?? 0),
                    'error' => (int) ($counts['error'] ?? 0),
                ],
                'operations' => $operations,
            ],
        ]);
    }
}
