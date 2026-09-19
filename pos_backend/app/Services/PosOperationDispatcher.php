<?php

declare(strict_types=1);

namespace Pos\Services;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Pos\Constants\PosScope;
use Pos\Exceptions\BusinessException;
use Pos\Models\PosTerminal;
use Pos\Models\Product;
use Pos\Models\StockMovement;

/**
 * One operation vocabulary, whether it arrived alone or inside a batch.
 *
 * A till that has been offline sends `/sync/push` with many operations of
 * mixed types; an online one posts a single `/sales`. Both land here, so the
 * two paths cannot drift into behaving differently — which is the bug this
 * shape exists to prevent, since only one of them is exercised on a good day.
 */
class PosOperationDispatcher {
    public function __construct(
        private readonly PosSaleService $sales,
        private readonly PosStockService $stock,
    ) {}

    /** Scope required per operation type — checked here, not at the route. */
    private const SCOPES = [
        'sale.create' => PosScope::SALES_WRITE,
        'sale.cancel' => PosScope::SALES_WRITE,
        'product.create' => PosScope::PRODUCTS_WRITE,
        'product.update' => PosScope::PRODUCTS_WRITE,
        'stock.movement' => PosScope::STOCK_WRITE,
        'stock.stocktake' => PosScope::STOCK_WRITE,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    public function dispatch(
        PosTerminal $terminal,
        string $type,
        array $payload,
        ?Carbon $occurredAt,
        int $userId,
        ?PersonalAccessToken $token = null,
    ): array {
        $scope = self::SCOPES[$type] ?? null;

        if ($scope === null) {
            throw new BusinessException('Noma\'lum amal turi: '.$type);
        }

        // A batch can carry several types behind one request, so the scope
        // check belongs per operation rather than on the route that received
        // the batch — PosScopeMiddleware can only guard the route, and the
        // route is the same one for every type in the batch.
        if ($token !== null && ! $token->can($scope->value)) {
            throw new BusinessException('Bu amal uchun ruxsat yo\'q: '.$type);
        }

        return match ($type) {
            'sale.create' => ['sale' => $this->sales->create($terminal, $payload, $occurredAt, $userId)->toArray()],
            'sale.cancel' => ['sale' => $this->sales->cancel($terminal, (int) ($payload['sale_id'] ?? 0), $occurredAt)->toArray()],
            'product.create' => ['product' => $this->createProduct($terminal, $payload, $occurredAt, $userId)->toArray()],
            'product.update' => ['product' => $this->updateProduct($terminal, $payload)->toArray()],
            'stock.movement' => ['movement' => $this->stockMovement($terminal, $payload, $occurredAt, $userId)],
            'stock.stocktake' => ['movement' => $this->stocktake($terminal, $payload, $occurredAt, $userId)],
        };
    }

    /** @throws BusinessException */
    private function createProduct(PosTerminal $terminal, array $payload, ?Carbon $occurredAt, int $userId): Product {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new BusinessException('Mahsulot nomi kerak');
        }

        $barcode = $payload['barcode'] ?? null;

        if ($barcode !== null && Product::query()->where('shop_id', $terminal->shop_id)->where('barcode', $barcode)->exists()) {
            throw new BusinessException('Bu barcode bilan mahsulot allaqachon bor: '.$barcode);
        }

        $tracked = array_key_exists('quantity', $payload) && $payload['quantity'] !== null;

        $product = Product::create([
            'shop_id' => $terminal->shop_id,
            'name' => $name,
            'code' => $payload['code'] ?? null,
            'barcode' => $barcode,
            'price' => $payload['price'] ?? null,
            'unit_id' => $payload['unit_id'] ?? null,
            'currency_id' => $payload['currency_id'] ?? null,
            // Absent means untracked, which is not the same as zero stock.
            // Tracked products start at zero and receive their opening balance
            // as a ledger row below.
            'quantity' => $tracked ? 0 : null,
            'low_stock_threshold' => $payload['low_stock_threshold'] ?? null,
            'image_url' => $payload['image_url'] ?? null,
        ]);

        if ($tracked) {
            // Through the ledger, not into the column: a balance written
            // straight to the cache vanishes the first time it is rebuilt.
            $this->stock->recordOpening($product, (float) $payload['quantity'], $occurredAt, $userId);
        }

        return $product->refresh();
    }

    /** @throws BusinessException */
    private function updateProduct(PosTerminal $terminal, array $payload): Product {
        $product = $this->findProduct($terminal, (int) ($payload['id'] ?? 0));

        // array_key_exists, not ??: an explicit null means "clear this", while
        // an absent key means "leave it alone". Collapsing the two lets a
        // client that sends a partial body wipe fields it never mentioned.
        foreach (['name', 'code', 'barcode', 'price', 'unit_id', 'currency_id', 'low_stock_threshold', 'image_url'] as $field) {
            if (array_key_exists($field, $payload)) {
                $product->{$field} = $payload[$field];
            }
        }

        $product->save();

        return $product;
    }

    /** @throws BusinessException */
    private function stockMovement(PosTerminal $terminal, array $payload, ?Carbon $occurredAt, int $userId): ?array {
        $product = $this->findProduct($terminal, (int) ($payload['product_id'] ?? 0));
        $delta = (float) ($payload['quantity'] ?? 0);

        if ($delta === 0.0) {
            throw new BusinessException('Miqdor nol bo\'lishi mumkin emas');
        }

        $type = (string) ($payload['type'] ?? StockMovement::TYPE_ADJUSTMENT);

        return $this->stock->record(
            $product, $type, $delta, $occurredAt, $userId,
            note: $payload['note'] ?? null,
        )?->toArray();
    }

    /** @throws BusinessException */
    private function stocktake(PosTerminal $terminal, array $payload, ?Carbon $occurredAt, int $userId): ?array {
        $product = $this->findProduct($terminal, (int) ($payload['product_id'] ?? 0));

        return $this->stock->recordStocktake(
            $product,
            (float) ($payload['counted_quantity'] ?? 0),
            $occurredAt,
            $userId,
            $payload['note'] ?? null,
        )?->toArray();
    }

    /** @throws BusinessException */
    private function findProduct(PosTerminal $terminal, int $id): Product {
        $product = Product::query()->where('shop_id', $terminal->shop_id)->find($id);

        if ($product === null) {
            throw new BusinessException('Mahsulot topilmadi: #'.$id);
        }

        return $product;
    }
}
