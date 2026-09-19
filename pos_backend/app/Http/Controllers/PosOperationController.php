<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Pos\Services\PosIdempotencyService;
use Pos\Services\PosOperationDispatcher;

/**
 * Single writes, for a till that is online right now.
 *
 * Every one of them goes through the same idempotency ledger and the same
 * dispatcher as a batched one — the online path and the offline path differ
 * only in how many operations arrive at once. Keeping them one code path is
 * what stops the rarely-exercised offline branch from quietly rotting.
 *
 * `client_operation_id` is required here too, even though an online till could
 * technically do without it. A response that never arrives looks exactly like
 * a request that never landed, and the retry is what rings the sale up twice.
 */
class PosOperationController extends Controller {
    public function __construct(
        private readonly PosIdempotencyService $idempotency,
        private readonly PosOperationDispatcher $dispatcher,
    ) {}

    public function sale(Request $request): JsonResponse {
        return $this->run($request, 'sale.create', [
            // `exists`, not just `integer`: a till holding a currency id that
            // was removed since it last synced would otherwise hit a foreign
            // key and get "Server Error" — which tells it nothing and looks
            // like the POS is broken rather than its cached list being stale.
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['nullable', 'string', 'max:24'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            // Optional. Our own till sends one, because a cashier may
            // override it; another POS may prefer the server to price the
            // line from the catalogue rather than reimplement the rules for
            // unit, currency and credit. Omitted and unresolvable is refused
            // in the service — nothing here invents a price.
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    public function cancelSale(Request $request): JsonResponse {
        return $this->run($request, 'sale.cancel', ['sale_id' => ['required', 'integer']]);
    }

    public function createProduct(Request $request): JsonResponse {
        return $this->run($request, 'product.create', [
            'name' => ['required', 'string', 'max:191'],
            'code' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'quantity' => ['nullable', 'numeric'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'string', 'max:512'],

            // Extra ways to sell it — "1 karobka = 12 dona" — with prices.
            // Listed here because validate() returns only what it was told
            // about: without these rules the whole array is dropped on the
            // floor and the product silently arrives with one unit.
            'units' => ['nullable', 'array', 'max:10'],
            'units.*.unit_id' => ['required', 'integer', 'exists:units,id'],
            'units.*.numerator' => ['required', 'integer', 'min:1'],
            'units.*.denominator' => ['required', 'integer', 'min:1'],
            'units.*.is_base' => ['nullable', 'boolean'],
            'units.*.is_active' => ['nullable', 'boolean'],
            'units.*.prices' => ['nullable', 'array', 'max:20'],
            'units.*.prices.*.currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'units.*.prices.*.amount' => ['required', 'numeric', 'min:0'],
            'units.*.prices.*.type' => ['nullable', 'string', 'in:sale,credit'],
        ]);
    }

    public function updateProduct(Request $request): JsonResponse {
        return $this->run($request, 'product.update', [
            'id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'currency_id' => ['sometimes', 'nullable', 'integer', 'exists:currencies,id'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);
    }

    public function createClient(Request $request): JsonResponse {
        return $this->run($request, 'client.create', [
            'name' => ['required', 'string', 'max:191'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    public function clientPayment(Request $request): JsonResponse {
        return $this->run($request, 'client.payment', [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'sale_id' => ['nullable', 'integer', 'exists:sales,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            // Which debt is being settled. Omitted falls back to the shop's
            // own currency in the dispatcher, which is what an older till
            // that does not ask the question is handing over.
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'payment_type' => ['nullable', 'string', 'max:24'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    public function stockMovement(Request $request): JsonResponse {
        return $this->run($request, 'stock.movement', [
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric'],
            'type' => ['nullable', 'string', 'max:16'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    public function stocktake(Request $request): JsonResponse {
        return $this->run($request, 'stock.stocktake', [
            'product_id' => ['required', 'integer'],
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function run(Request $request, string $type, array $rules): JsonResponse {
        $terminal = $request->attributes->get('pos_terminal');

        $validated = $request->validate(array_merge($rules, [
            'client_operation_id' => ['required', 'uuid'],
            'occurred_at' => ['nullable', 'date'],
        ]));

        $payload = collect($validated)->except(['client_operation_id', 'occurred_at'])->all();
        $occurredAt = isset($validated['occurred_at']) ? Carbon::parse($validated['occurred_at']) : null;
        $token = $request->user()?->currentAccessToken();

        $result = $this->idempotency->run(
            $terminal,
            $validated['client_operation_id'],
            $type,
            $payload,
            $occurredAt,
            fn () => $this->dispatcher->dispatch(
                $terminal, $type, $payload, $occurredAt, (int) $terminal->user_id, $token,
            ),
        );

        return response()->json(['data' => $result->toArray()], $result->httpStatus);
    }
}
