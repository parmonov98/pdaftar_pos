<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\SalesCalcItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Pos\Models\PosTerminal;

/**
 * What the shop has sold, for the POS history screen.
 *
 * The till already knows its OWN sales — they are in its outbox. This exists for
 * the other question a seller actually asks: what did the shop sell today,
 * including the sales the other seller rang up on the other device. That answer
 * only exists on the server.
 *
 * Scoped to sales, not to debts in general: a `sales_calc_list_id` is what makes
 * a debt a sale rather than a hand-written nasiya entry, and mixing the two would
 * make the day's totals disagree with the Sotuv reports the owner already reads.
 */
class PosSaleHistoryController extends Controller {
    /**
     * @OA\Get(
     *     path="/api/pos/v1/sales/recent",
     *     summary="Do'konning so'nggi sotuvlari",
     *     description="Barcha sotuvchilarning sotuvlari, eng yangisi birinchi. Kassaning o'z navbati emas — bu serverdagi haqiqiy tarix.",
     *     tags={"POS Sales"},
     *     security={{"posToken":{}}},
     *
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=50, maximum=200)),
     *     @OA\Parameter(name="mine", in="query", description="1 bo'lsa faqat shu sotuvchining sotuvlari", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function recent(Request $request): JsonResponse {
        /** @var PosTerminal $terminal */
        $terminal = $request->attributes->get('pos_terminal');

        $limit = min(200, max(1, (int) $request->query('limit', '50')));

        $sales = Debt::query()
            ->where('shop_id', $terminal->shop_id)
            ->whereNotNull('sales_calc_list_id')
            // `deleted_at` on debts is a plain column with no global scope —
            // see the backend CLAUDE.md. Omitting this leaks cancelled sales
            // into the history and inflates the day's total.
            ->whereNull('deleted_at')
            ->when($request->boolean('mine'), fn ($q) => $q->where('created_by_id', $terminal->user_id))
            ->with(['client:id,name,phone_number', 'user:id,name'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        // One query for every line rather than one per sale: this list is the
        // first thing the history screen paints and N+1 over 50 sales is
        // exactly the kind of lag that gets a POS called slow.
        $lines = SalesCalcItem::query()
            ->whereIn('sales_calc_list_id', $sales->pluck('sales_calc_list_id')->filter())
            ->get()
            ->groupBy('sales_calc_list_id');

        return response()->json([
            'success' => true,
            'data' => $sales->map(function (Debt $sale) use ($lines) {
                $own = $lines->get($sale->sales_calc_list_id) ?? collect();

                return [
                    'id' => $sale->id,
                    'total' => (float) $sale->base_amount,
                    'paid_amount' => (float) ($sale->paid_amount ?? 0),
                    'is_credit' => (float) ($sale->paid_amount ?? 0) < (float) $sale->base_amount,
                    'currency_id' => $sale->currency_id,
                    'client_name' => $sale->client?->name,
                    'client_phone' => $sale->client?->phone_number,
                    'seller_name' => $sale->user?->name,
                    'is_cancelled' => (bool) $sale->is_cancelled,
                    'created_at' => $sale->created_at
                        ? Carbon::parse($sale->created_at)->toIso8601String()
                        : null,
                    'items' => $own
                        // The discount rides as its own product-less line (see
                        // PosSaleService); it belongs in the total, not in a
                        // list of things the customer carried out.
                        ->filter(fn (SalesCalcItem $i) => $i->product_id !== null)
                        ->map(fn (SalesCalcItem $i) => [
                            'product_id' => $i->product_id,
                            'name' => $i->label,
                            'quantity' => $i->quantity === null ? null : (float) $i->quantity,
                            'total' => (float) $i->result,
                        ])->values()->all(),
                ];
            })->all(),
        ]);
    }
}
