<?php

declare(strict_types=1);

namespace Pos\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pos\Models\Product;
use Pos\Models\StockMovement;

/**
 * Stock, kept as a ledger.
 *
 * `stock_movements` is the truth; `products.quantity` is a cache rebuilt from
 * it. Every write goes through here so the two cannot part company.
 *
 * Two properties this buys, both of which matter to an offline till:
 *
 *   - **Reversal is deletion.** Cancelling a sale removes its movements and
 *     recomputes. Nothing has to remember to add quantities back on each of
 *     the several paths that can undo a sale.
 *   - **Order does not matter.** Deltas commute, so two tills that were
 *     offline all day can sync in either order and land on the same balance.
 *     This is why nothing here ever writes an absolute quantity.
 */
class PosStockService {
    /**
     * Append one movement and refresh the cache.
     *
     * @param  float  $delta  signed — negative takes stock out
     */
    public function record(
        Product $product,
        string $type,
        float $delta,
        ?Carbon $occurredAt = null,
        ?int $userId = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $note = null,
    ): ?StockMovement {
        // An untracked product has no balance to move. Selling one is fine;
        // it simply leaves no ledger entry.
        if (! $product->isTracked()) {
            return null;
        }

        return DB::transaction(function () use ($product, $type, $delta, $occurredAt, $userId, $sourceType, $sourceId, $note) {
            $movement = StockMovement::create([
                'shop_id' => $product->shop_id,
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => $delta,
                'occurred_at' => $occurredAt ?? now(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'note' => $note,
                'user_id' => $userId,
            ]);

            $this->refreshCache($product);

            return $movement;
        });
    }

    /**
     * The balance a product starts life with.
     *
     * Opening stock has to enter the ledger like everything else. Writing it
     * straight into `products.quantity` looks like it works right up until the
     * first sale, when the cache is rebuilt from movements that never included
     * it and the opening balance silently disappears.
     */
    public function recordOpening(Product $product, float $quantity, ?Carbon $occurredAt = null, ?int $userId = null): ?StockMovement {
        if ($quantity == 0.0) {
            return null;
        }

        return $this->record(
            $product,
            StockMovement::TYPE_OPENING,
            $quantity,
            $occurredAt,
            $userId,
            'opening',
        );
    }

    /** Stock leaving on a sale. Never refuses — see PosSaleService. */
    public function recordSale(Product $product, float $quantity, int $saleId, ?Carbon $occurredAt, ?int $userId): ?StockMovement {
        return $this->record(
            $product,
            StockMovement::TYPE_SALE,
            -abs($quantity),
            $occurredAt,
            $userId,
            'sale',
            $saleId,
        );
    }

    /**
     * A counted balance, turned into the delta that reaches it.
     *
     * The balance it measures against is the one **as of `occurredAt`**, not
     * the one right now. That distinction is the whole reason `occurred_at`
     * exists: a count taken at 10:00 and synced at 18:00, measured against the
     * 18:00 balance, silently erases every sale another till made in between.
     */
    public function recordStocktake(
        Product $product,
        float $countedQuantity,
        ?Carbon $occurredAt = null,
        ?int $userId = null,
        ?string $note = null,
    ): ?StockMovement {
        if (! $product->isTracked()) {
            return null;
        }

        $at = $occurredAt ?? now();
        $balanceThen = $this->balanceAsOf($product, $at);
        $delta = $countedQuantity - $balanceThen;

        if (abs($delta) < 0.000001) {
            return null;
        }

        return $this->record(
            $product,
            StockMovement::TYPE_ADJUSTMENT,
            $delta,
            $at,
            $userId,
            'stocktake',
            null,
            $note,
        );
    }

    /** The balance the ledger had at a moment in time. */
    public function balanceAsOf(Product $product, Carbon $at): float {
        return (float) StockMovement::query()
            ->where('product_id', $product->id)
            ->where('occurred_at', '<=', $at)
            ->sum('quantity');
    }

    public function currentStock(Product $product): float {
        return (float) StockMovement::query()
            ->where('product_id', $product->id)
            ->sum('quantity');
    }

    /**
     * Undo everything one source did.
     *
     * Rows are removed rather than offset, because an offsetting entry makes
     * the ledger read as "sold then returned" — which is a different thing
     * from "never happened", and reports cannot tell them apart afterwards.
     */
    public function reverseFor(string $sourceType, int $sourceId): int {
        return DB::transaction(function () use ($sourceType, $sourceId) {
            $movements = StockMovement::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->get();

            $productIds = $movements->pluck('product_id')->unique();

            StockMovement::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->delete();

            Product::query()->whereIn('id', $productIds)->get()
                ->each(fn (Product $p) => $this->refreshCache($p));

            return $movements->count();
        });
    }

    /** Rebuild `products.quantity` from the ledger. */
    public function refreshCache(Product $product): ?float {
        if (! $product->isTracked()) {
            return null;
        }

        $balance = $this->currentStock($product);

        // Written with a direct update rather than save(): the model in hand
        // may be stale, and a save() would push its other attributes back over
        // whatever a concurrent sale just wrote.
        Product::query()->whereKey($product->id)->update(['quantity' => $balance]);
        $product->quantity = $balance;

        return $balance;
    }
}
