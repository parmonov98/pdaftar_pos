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
            ->with(['items', 'user:id,name'])
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
                'status' => $sale->status,
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
