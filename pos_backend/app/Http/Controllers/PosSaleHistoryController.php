<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

        return response()->json([
            'data' => $query->get()->map(fn (Sale $sale) => [
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
            ])->all(),
        ]);
    }
}
