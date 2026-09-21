<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Pos\Models\Sale;

/**
 * Recent sales, for the Tarix screen and for reprinting a receipt.
 */
class PosSaleHistoryController extends Controller {
    public function recent(Request $request): JsonResponse {
        $terminal = $request->attributes->get('pos_terminal');

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $query = Sale::query()
            ->where('shop_id', $terminal->shop_id)
            ->with(['items', 'user:id,name', 'client:id,name,phone_number'])
            // Repayments taken against this sale AFTER it was rung up. They
            // survive a cancellation — the goods go back, the cash the
            // customer already handed over does not — so the till has to be
            // able to warn that cancelling leaves the shop owing it back.
            ->withSum('payments as repaid_amount', 'amount')
            // occurred_at, not created_at: a till that was offline all morning
            // sends its sales at noon, and ordering by arrival would file them
            // after everything that happened while it was away.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit((int) ($data['limit'] ?? 30));

        // "Mine" means this cashier, not this terminal — a shared till has
        // several people behind it in a day.
        if (filter_var($data['mine'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('user_id', $terminal->user_id);
        }

        /*
         * One row per BASKET, not per sale row.
         *
         * A basket spanning two currencies is two `sales` rows — a sale has
         * one currency all the way down to the debt it leaves behind — but
         * the customer had one visit and the cashier is looking for one
         * entry. Rows sharing a `sale_group_id` are folded together here,
         * carrying a total per currency rather than a sum of them.
         *
         * Folded AFTER the limit, so "last 30" stays a predictable amount of
         * work; the effect is that a page may hold slightly fewer entries
         * than sales, which is exactly what the seller is asking for.
         */
        $grouped = $query->get()->groupBy(
            // Ungrouped sales keep their own identity: keying them on the id
            // means one row each, without a special case below.
            fn (Sale $sale) => $sale->sale_group_id ?? 'single:'.$sale->id,
        );

        return response()->json([
            'data' => $grouped->map(function ($parts) {
                /** @var Collection<int, Sale> $parts */
                // The oldest part is the basket's identity: its id is what a
                // cancel names and what a receipt was filed under.
                $sale = $parts->sortBy('id')->first();

                return $this->row($sale, $parts);
            })->values()->all(),
        ]);
    }

    /**
     * @param  Collection<int, Sale>  $parts
     * @return array<string, mixed>
     */
    private function row(Sale $sale, $parts): array {
        // The fields that describe the basket as a whole, taken from its
        // first part — who sold it, to whom, when.
        $base = [
            'id' => $sale->id,
            'total' => (float) $sale->total,
            'discount_amount' => (float) $sale->discount_amount,
            'paid_amount' => (float) $sale->paid_amount,
            'payment_type' => $sale->payment_type,
            'currency_id' => $sale->currency_id,

            // What the row MEANS, not just what column it holds. The till
            // badges naqd and nasiya differently and cannot work that out
            // from `status` — a cancelled credit sale and a cancelled cash
            // one are the same status and different events.
            //
            // These were the contract the till was written against and the
            // half that was never sent: every row read as an uncancelled
            // nasiya to "Naqd xaridor" sold by "—", and a cancelled sale
            // was indistinguishable from a live one — while still being
            // counted into the day's takings.
            'kind' => $sale->isCredit() ? 'debt' : 'income',
            'is_credit' => $sale->isCredit(),
            'is_cancelled' => $sale->isCancelled(),
            'status' => $sale->status,
            'cancelled_at' => $sale->cancelled_at?->toIso8601String(),

            'client_name' => $sale->client?->name,
            'client_phone' => $sale->client?->phone_number,
            'seller_name' => $sale->user?->name,
            'description' => $sale->note,
            'repaid_amount' => (float) ($sale->repaid_amount ?? 0),

            // When it HAPPENED, under both names. `created_at` is what the
            // till reads; it is deliberately fed occurred_at, because a
            // sale rung up offline at 09:00 and pushed at noon belongs to
            // the morning on the receipt as well as in the ordering.
            'created_at' => $sale->occurred_at?->toIso8601String(),
            'occurred_at' => $sale->occurred_at?->toIso8601String(),
            'seller' => $sale->user?->name,

            'items' => $sale->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'name' => $item->name,
                'unit_name' => $item->unit_name,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'total' => (float) $item->total,
            ])->all(),
        ];

        /*
         * The money, per currency, one entry per part.
         *
         * Never summed: this shop's own history has 589 000 UZS beside
         * 620 012 USD, and a basket holding both has two totals and no third
         * number that means anything.
         */
        $base['totals'] = $parts
            ->sortBy('id')
            ->map(fn (Sale $part) => [
                'sale_id' => $part->id,
                'currency_id' => $part->currency_id,
                'total' => (float) $part->total,
                'paid_amount' => (float) $part->paid_amount,
                'discount_amount' => (float) $part->discount_amount,
                'is_cancelled' => $part->isCancelled(),
            ])
            ->values()
            ->all();

        // Every id in the basket, so the till can reprint or trace any half.
        $base['sale_ids'] = $parts->sortBy('id')->pluck('id')->values()->all();
        $base['sale_group_id'] = $sale->sale_group_id;

        // Lines from every part, in the order they were rung up.
        $base['items'] = $parts
            ->sortBy('id')
            ->flatMap(fn (Sale $part) => $part->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'name' => $item->name,
                'unit_name' => $item->unit_name,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'total' => (float) $item->total,
                'currency_id' => $part->currency_id,
            ]))
            ->values()
            ->all();

        // A half-cancelled basket is not a cancelled one. The tag, the
        // greying and the day's takings all key on this, and calling it
        // cancelled while one half still stands would drop live money out of
        // the day's total.
        $base['is_cancelled'] = $parts->every(fn (Sale $part) => $part->isCancelled());
        $base['is_credit'] = $parts->contains(fn (Sale $part) => $part->isCredit());
        $base['kind'] = $base['is_credit'] ? 'debt' : 'income';
        $base['repaid_amount'] = (float) $parts->sum(fn (Sale $part) => $part->repaid_amount ?? 0);

        return $base;
    }
}
