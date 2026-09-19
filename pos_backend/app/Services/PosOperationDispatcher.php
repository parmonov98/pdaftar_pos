<?php

declare(strict_types=1);

namespace Pos\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Pos\Constants\PosScope;
use Pos\Exceptions\BusinessException;
use Pos\Models\Client;
use Pos\Models\ClientPayment;
use Pos\Models\PosTerminal;
use Pos\Models\Product;
use Pos\Models\ProductPrice;
use Pos\Models\ProductUnit;
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
        private readonly PosPricingService $pricing,
    ) {}

    /** Scope required per operation type — checked here, not at the route. */
    private const SCOPES = [
        'sale.create' => PosScope::SALES_WRITE,
        'sale.cancel' => PosScope::SALES_WRITE,
        'product.create' => PosScope::PRODUCTS_WRITE,
        'product.update' => PosScope::PRODUCTS_WRITE,
        'client.create' => PosScope::CLIENTS_WRITE,
        'client.payment' => PosScope::CLIENTS_WRITE,
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

        // The shape PosIdempotencyService stores: `entity` is what the
        // operation produced — it becomes pos_operations.entity_id, which is
        // how support traces a receipt back to a row — and `data` is what the
        // till is handed back, including on a replay.
        return match ($type) {
            'sale.create' => $this->wrap($sale = $this->sales->create($terminal, $payload, $occurredAt, $userId), 'sale', $sale->toArray()),
            'sale.cancel' => $this->wrap($cancelled = $this->sales->cancel($terminal, (int) ($payload['sale_id'] ?? 0), $occurredAt), 'sale', $cancelled->toArray()),
            'product.create' => $this->wrap($created = $this->createProduct($terminal, $payload, $occurredAt, $userId), 'product', $created->toArray()),
            'product.update' => $this->wrap($updated = $this->updateProduct($terminal, $payload), 'product', $updated->toArray()),
            'client.create' => $this->wrap($client = $this->createClient($terminal, $payload), 'client', $client->toArray()),
            'client.payment' => $this->wrap($payment = $this->clientPayment($terminal, $payload, $occurredAt, $userId), 'payment', $payment->toArray()),
            'stock.movement' => $this->wrap(null, 'movement', $this->stockMovement($terminal, $payload, $occurredAt, $userId)),
            'stock.stocktake' => $this->wrap(null, 'movement', $this->stocktake($terminal, $payload, $occurredAt, $userId)),
        };
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array{entity: Model|null, data: array<string, mixed>}
     */
    private function wrap(?Model $entity, string $key, ?array $data): array {
        return ['entity' => $entity, 'data' => $data === null ? [] : [$key => $data]];
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

        // Every product gets a base unit, even a single-unit one: the selling
        // path resolves units, and a product with none is a special case that
        // would have to be handled at every call site instead of here once.
        $this->pricing->ensureBaseUnit($product);

        // Extra units — "1 karobka = 12 dona" — and their prices.
        foreach ($payload['units'] ?? [] as $extra) {
            $this->addUnit($product, $extra);
        }

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

    /**
     * Attach one more way of selling this product, with its prices.
     *
     * @param  array<string, mixed>  $spec
     *
     * @throws BusinessException
     */
    private function addUnit(Product $product, array $spec): ProductUnit {
        $num = (int) ($spec['numerator'] ?? 1);
        $den = (int) ($spec['denominator'] ?? 1);

        if ($num <= 0 || $den <= 0) {
            throw new BusinessException('Birlik nisbati noldan katta bo\'lishi kerak');
        }

        $unit = ProductUnit::updateOrCreate(
            ['product_id' => $product->id, 'unit_id' => (int) $spec['unit_id']],
            [
                'shop_id' => $product->shop_id,
                'base_units_numerator' => $num,
                'base_units_denominator' => $den,
                'is_base' => $num === 1 && $den === 1 && ($spec['is_base'] ?? false),
                'is_active' => $spec['is_active'] ?? true,
            ],
        );

        foreach ($spec['prices'] ?? [] as $price) {
            ProductPrice::updateOrCreate(
                [
                    'product_unit_id' => $unit->id,
                    'currency_id' => (int) $price['currency_id'],
                    'price_type' => $price['type'] ?? ProductPrice::TYPE_SALE,
                ],
                [
                    'shop_id' => $product->shop_id,
                    'product_id' => $product->id,
                    'amount' => (float) $price['amount'],
                ],
            );
        }

        return $unit;
    }

    /** @throws BusinessException */
    private function createClient(PosTerminal $terminal, array $payload): Client {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new BusinessException('Mijoz ismi kerak');
        }

        return Client::create([
            'shop_id' => $terminal->shop_id,
            'name' => $name,
            'phone_number' => $payload['phone_number'] ?? null,
            'note' => $payload['note'] ?? null,
        ]);
    }

    /**
     * Money coming back against a debt.
     *
     * Overpaying is allowed: a client settling 50,000 against a 30,000 debt
     * leaves 20,000 of credit, and refusing the note they are holding out
     * helps nobody. It shows as a negative balance, which is visible.
     *
     * @throws BusinessException
     */
    private function clientPayment(PosTerminal $terminal, array $payload, ?Carbon $occurredAt, int $userId): ClientPayment {
        $client = Client::query()
            ->where('shop_id', $terminal->shop_id)
            ->find((int) ($payload['client_id'] ?? 0));

        if ($client === null) {
            throw new BusinessException('Mijoz topilmadi');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 6);

        if ($amount <= 0) {
            throw new BusinessException('Summa noldan katta bo\'lishi kerak');
        }

        return ClientPayment::create([
            'shop_id' => $terminal->shop_id,
            'client_id' => $client->id,
            'sale_id' => $payload['sale_id'] ?? null,
            'amount' => $amount,
            'payment_type' => $payload['payment_type'] ?? null,
            'note' => $payload['note'] ?? null,
            'user_id' => $userId,
            'pos_terminal_id' => $terminal->id,
            'occurred_at' => $occurredAt ?? now(),
        ]);
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
