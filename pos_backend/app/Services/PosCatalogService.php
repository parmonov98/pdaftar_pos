<?php

declare(strict_types=1);

namespace Pos\Services;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopExpenseCategory;
use App\Models\ShopIncomeCategory;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The read half of the protocol: what the till copies down and keeps.
 *
 * Everything here is incremental and resumable, because a till syncing a
 * 5 000-product catalogue over a bad connection will be interrupted, and a
 * protocol that restarts from zero on every interruption never finishes.
 *
 * The cursor is the pair (updated_at, id), not updated_at alone. A plain
 * timestamp cursor loses rows silently whenever more items share one second
 * than fit in a page — a bulk price update or an import writes hundreds of rows
 * in the same second, the page cuts through the middle of them, and the next
 * request asks for `> that second` and never sees the remainder. Those products
 * then sit stale on the till forever, with nothing to indicate anything was
 * missed. Keyset paging on the pair cannot skip a row.
 */
class PosCatalogService {
    /** Everything a till may pull. */
    public const ENTITIES = ['products', 'clients', 'suppliers', 'units', 'currencies', 'income_categories', 'expense_categories'];

    private const MAX_LIMIT = 1000;

    /**
     * @param  string[]  $entities
     * @return array<string, mixed>
     */
    public function pull(Shop $shop, array $entities, ?Carbon $since, ?int $sinceId, int $limit): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $requested = array_values(array_intersect($entities, self::ENTITIES));

        if ($requested === []) {
            $requested = self::ENTITIES;
        }

        $data = [];
        $hasMore = false;
        $cursor = ['updated_at' => $since?->toIso8601String(), 'id' => $sinceId];

        foreach ($requested as $entity) {
            [$rows, $entityHasMore, $entityCursor] = match ($entity) {
                'products' => $this->page(
                    $this->productsQuery($shop),
                    $since,
                    $sinceId,
                    $limit,
                    fn (Product $p) => $this->productArray($p),
                ),
                'clients' => $this->page(
                    Client::query()->where('shop_id', $shop->id)->withTrashed(),
                    $since,
                    $sinceId,
                    $limit,
                    fn (Client $c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'phone_number' => $c->phone_number,
                        'address' => $c->address,
                        'is_blocked' => (bool) $c->is_client_blocked,
                        'deleted' => $c->deleted_at !== null,
                        'updated_at' => $c->updated_at?->toIso8601String(),
                    ],
                ),
                'suppliers' => $this->page(
                    Supplier::query()->where('shop_id', $shop->id)->withTrashed(),
                    $since,
                    $sinceId,
                    $limit,
                    fn (Supplier $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'phone_number' => $s->phone_number,
                        'deleted' => $s->deleted_at !== null,
                        'updated_at' => $s->updated_at?->toIso8601String(),
                    ],
                ),
                // Reference data is small, shared and effectively static — a
                // full list every time costs less than the bookkeeping of
                // paging it, and a till missing a unit renders a blank on
                // every product that uses it.
                'units' => [$this->units($shop), false, null],
                'currencies' => [$this->currencies(), false, null],
                'income_categories' => [$this->incomeCategories($shop), false, null],
                'expense_categories' => [$this->expenseCategories($shop), false, null],
                default => [[], false, null],
            };

            $data[$entity] = $rows;

            if ($entityHasMore) {
                $hasMore = true;
                // The slowest entity governs the shared cursor: resuming from
                // the furthest-along one would skip whatever the others had
                // not reached yet.
                if ($entityCursor !== null && $this->isEarlier($entityCursor, $cursor)) {
                    $cursor = $entityCursor;
                }
            }
        }

        return [
            'server_time' => now()->toIso8601String(),
            'has_more' => $hasMore,
            // When a page filled up, resume from where it stopped. When
            // everything fit, the next pull asks for changes since this
            // request started.
            'next_since' => $hasMore ? $cursor['updated_at'] : now()->toIso8601String(),
            'next_since_id' => $hasMore ? $cursor['id'] : null,
            'data' => $data,
        ];
    }

    /**
     * One product, by scanned barcode or internal code.
     *
     * Both are tried because a shop's own printed labels carry `code` while
     * manufacturer packaging carries `barcode`, and the cashier scanning them
     * cannot tell you which one they just waved at the reader.
     */
    public function lookup(Shop $shop, string $code): ?array {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $product = $this->productsQuery($shop)->where('barcode', $code)->first()
            ?? $this->productsQuery($shop)->where('code', $code)->first();

        return $product ? $this->productArray($product) : null;
    }

    /**
     * @param  callable(mixed): array  $map
     * @return array{0: list<array>, 1: bool, 2: ?array{updated_at: ?string, id: ?int}}
     */
    private function page(Builder $query, ?Carbon $since, ?int $sinceId, int $limit, callable $map): array {
        $query = $query->clone();

        if ($since !== null) {
            $query->where(function (Builder $q) use ($since, $sinceId) {
                $q->where('updated_at', '>', $since);

                if ($sinceId !== null) {
                    $q->orWhere(function (Builder $q2) use ($since, $sinceId) {
                        $q2->where('updated_at', '=', $since)->where('id', '>', $sinceId);
                    });
                }
            });
        }

        // Fetch one extra row purely to answer "is there more?" without a
        // second COUNT over the same range.
        $rows = $query
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $last = $rows->last();
        $cursor = $hasMore && $last !== null
            ? ['updated_at' => $last->updated_at?->toIso8601String(), 'id' => (int) $last->id]
            : null;

        return [$rows->map($map)->values()->all(), $hasMore, $cursor];
    }

    private function productsQuery(Shop $shop): Builder {
        return Product::query()
            ->where('shop_id', $shop->id)
            // Tombstones: the till must be told a product disappeared, or a
            // deleted item stays scannable on every device that ever synced it.
            ->withTrashed();
    }

    private function productArray(Product $product): array {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'barcode' => $product->barcode,
            'price' => $product->price === null ? null : (float) $product->price,
            // The cached ledger total. NULL means "never inventoried", which is
            // NOT the same as 0 — the till shows no stock figure for those
            // rather than a red out-of-stock badge on most of the catalogue.
            'quantity' => $product->quantity === null ? null : (float) $product->quantity,
            'unit_id' => $product->unit_id,
            'currency_id' => $product->currency_id,
            'supplier_id' => $product->supplier_id,
            'low_stock_threshold' => $product->low_stock_threshold === null ? null : (float) $product->low_stock_threshold,
            'deleted' => $product->deleted_at !== null,
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    private function units(Shop $shop): array {
        return Unit::query()
            ->where(fn (Builder $q) => $q->whereNull('shop_id')->orWhere('shop_id', $shop->id))
            ->get()
            ->map(fn (Unit $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'short_name' => $u->short_name,
                'is_default' => (bool) $u->is_default,
            ])
            ->all();
    }

    private function currencies(): array {
        return Currency::query()
            ->get()
            ->map(fn (Currency $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'sign' => $c->currency_sign,
            ])
            ->all();
    }

    private function incomeCategories(Shop $shop): array {
        return ShopIncomeCategory::query()
            ->where('shop_id', $shop->id)
            ->get()
            ->map(fn (ShopIncomeCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_sales_default' => (bool) $c->is_sales_default,
            ])
            ->all();
    }

    private function expenseCategories(Shop $shop): array {
        return ShopExpenseCategory::query()
            ->where('shop_id', $shop->id)
            ->get()
            ->map(fn (ShopExpenseCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_supplier_default' => (bool) $c->is_supplier_default,
            ])
            ->all();
    }

    /** @param array{updated_at: ?string, id: ?int} $a */
    private function isEarlier(array $a, array $b): bool {
        if ($b['updated_at'] === null) {
            return true;
        }

        if ($a['updated_at'] === null) {
            return false;
        }

        return $a['updated_at'] < $b['updated_at']
            || ($a['updated_at'] === $b['updated_at'] && (int) $a['id'] < (int) $b['id']);
    }
}
