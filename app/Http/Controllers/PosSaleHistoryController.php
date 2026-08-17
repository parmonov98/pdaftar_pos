<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use App\Models\Debt;
use App\Models\SalesCalcItem;
use App\Models\ShopIncome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Pos\Models\PosOperation;
use Pos\Models\PosTerminal;

/**
 * What the shop has sold, newest first.
 *
 * The till already knows its OWN sales — they are in its outbox. This answers
 * the other question a seller asks: what did the shop sell today, including what
 * the other seller rang up on the other device. That only exists on the server.
 *
 * A sale lives in one of two tables depending on how it was paid (see
 * PosSaleService), so this reads both and merges them:
 *
 *   PAID   → shop_incomes, found through the POS operation log, which is what
 *            links an income row back to the sale that created it
 *   NASIYA → debts carrying a sales_calc_list_id, which is what distinguishes a
 *            sale from a hand-written debt entry
 *
 * The nasiya half deliberately is not filtered to POS-created rows: a nasiya
 * sale rung up in the mobile app is the same shop's sale, and a seller asking
 * "what went out on credit today?" wants both.
 */
class PosSaleHistoryController extends Controller {
    /**
     * @OA\Get(
     *     path="/api/pos/v1/sales/recent",
     *     summary="Do'konning so'nggi sotuvlari",
     *     description="
     * Naqd sotuvlar Kassadan (`shop_incomes`), nasiya sotuvlar qarzlardan (`debts`) o'qiladi va
     * birlashtiriladi. `kind` maydoni qaysi biri ekanini aytadi.
     *
     * Kassaning o'z navbati emas — bu serverdagi haqiqiy tarix, boshqa sotuvchining sotuvlari ham.
     * ",
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
        $mine = $request->boolean('mine');

        // Each half is capped at the full limit before merging: taking limit/2
        // from each would hide a busy morning of cash sales behind three nasiya
        // ones. The merged list is trimmed afterwards.
        $paid = $this->paidSales($terminal, $limit, $mine);
        $credit = $this->creditSales($terminal, $limit, $mine);

        $rows = $paid->concat($credit)
            ->sortByDesc(fn (array $r) => $r['created_at'] ?? '')
            ->take($limit)
            ->values()
            ->all();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Paid counter sales, from Kassa.
     *
     * Joined through pos_operations because `shop_incomes` has no marker of its
     * own for "this was a POS sale" — and inventing one would mean a column on a
     * pDaftar table. The operation log is POS-owned and already holds the link,
     * including the calc list id the line items hang off.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function paidSales(PosTerminal $terminal, int $limit, bool $mine) {
        $operations = PosOperation::query()
            ->where('shop_id', $terminal->shop_id)
            ->where('type', 'sale.create')
            ->where('status', PosOperation::STATUS_APPLIED)
            ->where('entity_type', ShopIncome::class)
            ->when($mine, fn ($q) => $q->whereIn(
                'pos_terminal_id',
                PosTerminal::query()
                    ->where('shop_id', $terminal->shop_id)
                    ->where('user_id', $terminal->user_id)
                    ->pluck('id'),
            ))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $incomes = ShopIncome::query()
            ->whereIn('id', $operations->pluck('entity_id')->filter())
            ->with('createdBy:id,name')
            ->get()
            ->keyBy('id');

        $listIds = $operations
            ->map(fn (PosOperation $o) => $o->response['sales_calc_list_id'] ?? null)
            ->filter();

        $lines = $this->linesFor($listIds->all());

        return $operations->map(function (PosOperation $operation) use ($incomes, $lines) {
            /** @var ShopIncome|null $income */
            $income = $incomes->get($operation->entity_id);

            if ($income === null) {
                return;
            }

            $response = $operation->response ?? [];
            $listId = $response['sales_calc_list_id'] ?? null;

            return [
                'kind' => 'income',
                'id' => $income->id,
                'total' => (float) $income->amount,
                'paid_amount' => (float) $income->amount,
                'is_credit' => false,
                'discount_amount' => (float) ($response['discount_amount'] ?? 0),
                'currency_id' => $income->currency_id,
                'payment_type' => $income->payment_type instanceof \BackedEnum
                    ? $income->payment_type->value
                    : $income->payment_type,
                'client_name' => $response['client_name'] ?? null,
                'client_phone' => null,
                'seller_name' => $income->createdBy?->name,
                // The income row is soft-deleted on cancel, so a row still
                // present here has not been voided.
                'is_cancelled' => false,
                'description' => $income->description,
                'created_at' => $income->created_at?->toIso8601String(),
                'items' => $lines[$listId] ?? [],
            ];
        })->filter()->values();
    }

    /**
     * Nasiya sales, from debts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function creditSales(PosTerminal $terminal, int $limit, bool $mine) {
        $sales = Debt::query()
            ->where('shop_id', $terminal->shop_id)
            ->whereNotNull('sales_calc_list_id')
            // `deleted_at` on debts is a plain column with no global scope —
            // see the backend CLAUDE.md. Omitting this leaks deleted sales into
            // the history and inflates the day's total.
            ->whereNull('deleted_at')
            ->when($mine, fn ($q) => $q->where('created_by_id', $terminal->user_id))
            ->with(['client:id,name,phone_number', 'user:id,name'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $lines = $this->linesFor($sales->pluck('sales_calc_list_id')->filter()->all());

        return $sales->map(fn (Debt $sale) => [
            'kind' => 'debt',
            'id' => $sale->id,
            'total' => (float) $sale->base_amount,
            'paid_amount' => (float) ($sale->paid_amount ?? 0),
            'is_credit' => true,
            'discount_amount' => 0.0,
            'currency_id' => $sale->currency_id,
            'payment_type' => null,
            'client_name' => $sale->client?->name,
            'client_phone' => $sale->client?->phone_number,
            'seller_name' => $sale->user?->name,
            'is_cancelled' => (bool) $sale->is_cancelled,
            'description' => $sale->description,
            'created_at' => $sale->created_at
                ? Carbon::parse($sale->created_at)->toIso8601String()
                : null,
            'items' => $lines[$sale->sales_calc_list_id] ?? [],
        ]);
    }

    /**
     * Line items for many calc lists in one query.
     *
     * This list is the first thing the history screen paints, and N+1 over 50
     * sales is exactly the lag that gets a POS called slow.
     *
     * @param  list<int>  $listIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function linesFor(array $listIds): array {
        if ($listIds === []) {
            return [];
        }

        return SalesCalcItem::query()
            ->whereIn('sales_calc_list_id', $listIds)
            // The discount rides as its own product-less line (see
            // PosSaleService); it belongs in the total, not in a list of things
            // the customer carried out.
            ->whereNotNull('product_id')
            ->orderBy('position')
            ->get()
            ->groupBy('sales_calc_list_id')
            ->map(fn ($group) => $group->map(fn (SalesCalcItem $i) => [
                'product_id' => $i->product_id,
                'name' => $i->label,
                'quantity' => $i->quantity === null ? null : (float) $i->quantity,
                'total' => (float) $i->result,
            ])->values()->all())
            ->all();
    }
}
