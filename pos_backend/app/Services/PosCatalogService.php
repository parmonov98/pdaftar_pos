<?php

declare(strict_types=1);

namespace Pos\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Pos\Models\Client;
use Pos\Models\Currency;
use Pos\Models\Product;
use Pos\Models\Shop;
use Pos\Models\Unit;

/**
 * What the till downloads so it can sell with the network off.
 *
 * The cursor is the pair `(updated_at, id)`, not `updated_at` alone. Several
 * rows routinely share a timestamp — an import writes hundreds in the same
 * second — and a cursor of only the timestamp either repeats them forever or
 * steps over the ones it did not reach.
 *
 * Deleted rows are sent, marked `deleted: true`. A till that is never told a
 * product disappeared keeps it scannable for as long as that device lives.
 */
class PosCatalogService {
    public const ENTITIES = ['products', 'units', 'currencies', 'clients'];

    private const MAX_LIMIT = 1000;

    /**
     * @param  array<int, string>  $entities
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
                    Product::query()->where('shop_id', $shop->id)->withTrashed(),
                    $since, $sinceId, $limit,
                    fn (Product $p) => $this->productArray($p),
                ),
                'units' => $this->page(
                    Unit::query()->where('shop_id', $shop->id)->withTrashed(),
                    $since, $sinceId, $limit,
                    fn (Unit $u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'short_name' => $u->short_name,
                        'is_default' => (bool) $u->is_default,
                        'deleted' => $u->deleted_at !== null,
                        'updated_at' => $u->updated_at?->toIso8601String(),
                    ],
                ),
                'clients' => $this->page(
                    Client::query()->where('shop_id', $shop->id)->withTrashed(),
                    $since, $sinceId, $limit,
                    fn (Client $c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'phone_number' => $c->phone_number,
                        // Computed, not stored — see Client::balance().
                        'balance' => $c->balance(),
                        'deleted' => $c->deleted_at !== null,
                        'updated_at' => $c->updated_at?->toIso8601String(),
                    ],
                ),
                'currencies' => $this->page(
                    Currency::query(),
                    $since, $sinceId, $limit,
                    fn (Currency $c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'code' => $c->code,
                        'sign' => $c->sign,
                        'deleted' => false,
                        'updated_at' => $c->updated_at?->toIso8601String(),
                    ],
                ),
                default => [[], false, null],
            };

            $data[$entity] = $rows;
            $hasMore = $hasMore || $entityHasMore;

            if ($entityCursor !== null) {
                $cursor = $entityCursor;
            }
        }

        return [
            'server_time' => now()->toIso8601String(),
            'has_more' => $hasMore,
            // While pages remain, the next request resumes from the last row
            // handed out. Once everything fits, it asks for changes since now
            // — anything written during this request carries a later stamp and
            // is picked up next time rather than skipped.
            'next_since' => $hasMore ? $cursor['updated_at'] : now()->toIso8601String(),
            'next_since_id' => $hasMore ? $cursor['id'] : null,
            'data' => $data,
        ];
    }

    /** One product, resolved from whatever the scanner or the cashier typed. */
    public function lookup(Shop $shop, string $code): ?array {
        $product = Product::query()->where('shop_id', $shop->id)->where('barcode', $code)->first()
            ?? Product::query()->where('shop_id', $shop->id)->where('code', $code)->first();

        return $product === null ? null : $this->productArray($product);
    }

    /** @return array{0: list<array>, 1: bool, 2: ?array{updated_at: ?string, id: ?int}} */
    private function page(Builder $query, ?Carbon $since, ?int $sinceId, int $limit, callable $map): array {
        if ($since !== null) {
            $query->where(function (Builder $q) use ($since, $sinceId) {
                $q->where('updated_at', '>', $since);

                if ($sinceId !== null) {
                    // The tie-break half of the cursor: rows sharing the
                    // timestamp continue from the id already handed out.
                    $q->orWhere(function (Builder $q2) use ($since, $sinceId) {
                        $q2->where('updated_at', '=', $since)->where('id', '>', $sinceId);
                    });
                }
            });
        }

        $rows = $query->orderBy('updated_at')->orderBy('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();

        return [
            $rows->map($map)->values()->all(),
            $hasMore,
            $last === null ? null : ['updated_at' => $last->updated_at?->toIso8601String(), 'id' => (int) $last->id],
        ];
    }

    /** @return array<string, mixed> */
    private function productArray(Product $product): array {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'barcode' => $product->barcode,
            'price' => $product->price === null ? null : (float) $product->price,
            // NULL means "never inventoried", which is not the same as 0 — the
            // till shows no stock figure for those rather than an
            // out-of-stock badge across most of the catalogue.
            'quantity' => $product->quantity === null ? null : (float) $product->quantity,
            'unit_id' => $product->unit_id,
            'currency_id' => $product->currency_id,
            'supplier_id' => null,
            'low_stock_threshold' => $product->low_stock_threshold === null ? null : (float) $product->low_stock_threshold,
            'image_url' => $product->image_url,
            'deleted' => $product->deleted_at !== null,
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
