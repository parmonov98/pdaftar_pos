<?php

declare(strict_types=1);

namespace Pos\Services;

use App\Constants\ShopExpense\ShopExpensePaymentTypeEnum;
use App\Exceptions\Custom\BusinessException;
use App\Models\Client;
use App\Models\Debt;
use App\Models\Product;
use App\Models\ShopExpense;
use App\Models\ShopIncome;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Shop\ShopPermissionChecker;
use App\Services\Stock\StockService;
use App\Services\SupplierTransactionService;
use App\UseCases\SupplierInvoice\ProcessSupplierDeliveryUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pos\Constants\PosScope;
use Pos\Models\PosTerminal;

/**
 * The single operation vocabulary of the POS Integration API.
 *
 * Everything a till can write is one of the types below, and each type has
 * exactly one handler here. Both entry points — a single REST call and one
 * element of a /sync/push batch — go through this class, so an operation
 * performed online and the same operation replayed from an outbox two hours
 * later take an identical code path.
 *
 * That symmetry is the reason the design survives contact with a third-party
 * till: AliPOS does not implement "our REST API" or "our sync protocol", it
 * emits `sale.create` documents. Whether it posts them one at a time or a
 * hundred at once is its own business.
 *
 * Adding an operation means adding a case here and nothing else — the
 * idempotency, scope and audit machinery wraps every type uniformly.
 */
class PosOperationDispatcher {
    public const TYPE_SALE_CREATE = 'sale.create';

    public const TYPE_SALE_CANCEL = 'sale.cancel';

    public const TYPE_PRODUCT_CREATE = 'product.create';

    public const TYPE_PRODUCT_UPDATE = 'product.update';

    public const TYPE_CLIENT_CREATE = 'client.create';

    public const TYPE_SUPPLIER_CREATE = 'supplier.create';

    public const TYPE_SUPPLIER_TRANSACTION = 'supplier.transaction';

    public const TYPE_DELIVERY_CREATE = 'delivery.create';

    public const TYPE_STOCK_MOVEMENT = 'stock.movement';

    public const TYPE_CASH_INCOME = 'cash.income';

    public const TYPE_CASH_EXPENSE = 'cash.expense';

    /** Which token ability each operation demands. */
    private const SCOPES = [
        self::TYPE_SALE_CREATE => PosScope::SALES_WRITE,
        self::TYPE_SALE_CANCEL => PosScope::SALES_WRITE,
        self::TYPE_PRODUCT_CREATE => PosScope::PRODUCTS_WRITE,
        self::TYPE_PRODUCT_UPDATE => PosScope::PRODUCTS_WRITE,
        self::TYPE_CLIENT_CREATE => PosScope::CLIENTS_WRITE,
        self::TYPE_SUPPLIER_CREATE => PosScope::SUPPLIERS_WRITE,
        self::TYPE_SUPPLIER_TRANSACTION => PosScope::SUPPLIERS_WRITE,
        self::TYPE_DELIVERY_CREATE => PosScope::STOCK_WRITE,
        self::TYPE_STOCK_MOVEMENT => PosScope::STOCK_WRITE,
        self::TYPE_CASH_INCOME => PosScope::CASH_WRITE,
        self::TYPE_CASH_EXPENSE => PosScope::CASH_WRITE,
    ];

    public function __construct(
        private readonly PosIdempotencyService $idempotency,
        private readonly PosSaleService $sales,
        private readonly StockService $stockService,
        private readonly ShopPermissionChecker $permissions,
        private readonly SupplierTransactionService $supplierTransactions,
        private readonly ProcessSupplierDeliveryUseCase $delivery,
        private readonly PosCashCategoryResolver $cashCategories,
    ) {}

    /** @return string[] */
    public static function types(): array {
        return array_keys(self::SCOPES);
    }

    /**
     * Run one operation, idempotently.
     *
     * @param  array<string, mixed>  $operation  {client_operation_id, type, occurred_at?, payload}
     * @param  array<string, int>  $localIdMap  local_id => server id, for refs
     *                                          created earlier in the same batch
     */
    public function dispatch(
        PosTerminal $terminal,
        User $user,
        array $operation,
        array &$localIdMap = [],
    ): PosOperationResult {
        $type = (string) ($operation['type'] ?? '');
        $clientOperationId = (string) ($operation['client_operation_id'] ?? '');
        $payload = $operation['payload'] ?? [];

        if (! is_array($payload)) {
            $payload = [];
        }

        if ($clientOperationId === '') {
            return PosOperationResult::failed('', $type, 'client_operation_id talab qilinadi');
        }

        if (! isset(self::SCOPES[$type])) {
            return PosOperationResult::failed(
                $clientOperationId,
                $type,
                'Nomalum amal turi: '.$type.'. Ruxsat etilganlari: '.implode(', ', self::types()),
            );
        }

        $scope = self::SCOPES[$type];

        $token = $user->currentAccessToken();

        if ($token === null || ! $token->can($scope->value)) {
            return PosOperationResult::failed(
                $clientOperationId,
                $type,
                'Bu amal uchun ruxsat yo\'q: '.$scope->value,
                httpStatus: 403,
            );
        }

        $occurredAt = $this->parseOccurredAt($operation['occurred_at'] ?? null);

        // Resolve batch-local references BEFORE hashing, so a retry of the same
        // batch hashes identically whether or not the referenced product had
        // already been created on a previous attempt.
        $payload = $this->resolveLocalRefs($payload, $localIdMap);

        $result = $this->idempotency->run(
            $terminal,
            $clientOperationId,
            $type,
            $payload,
            $occurredAt,
            fn () => $this->handle($terminal, $user, $type, $payload, $occurredAt),
        );

        // Remember what a freshly created entity became, so later operations in
        // this same batch can point at it by the id the till invented offline.
        $localId = $payload['local_id'] ?? null;
        if (is_string($localId) && $localId !== '' && $result->status === 'applied') {
            $serverId = $result->data['id'] ?? $result->data['sale_id'] ?? null;
            if (is_int($serverId) || (is_string($serverId) && ctype_digit($serverId))) {
                $localIdMap[$localId] = (int) $serverId;
            }
        }

        return $result;
    }

    /**
     * @return array{data: array, entity?: ?Model}
     *
     * @throws BusinessException
     * @throws ValidationException
     */
    private function handle(
        PosTerminal $terminal,
        User $user,
        string $type,
        array $payload,
        ?Carbon $occurredAt = null,
    ): array {
        return match ($type) {
            self::TYPE_SALE_CREATE => $this->saleCreate($terminal, $user, $payload, $occurredAt),
            self::TYPE_SALE_CANCEL => $this->saleCancel($terminal, $user, $payload),
            self::TYPE_PRODUCT_CREATE => $this->productCreate($terminal, $user, $payload),
            self::TYPE_PRODUCT_UPDATE => $this->productUpdate($terminal, $payload),
            self::TYPE_CLIENT_CREATE => $this->clientCreate($terminal, $user, $payload),
            self::TYPE_SUPPLIER_CREATE => $this->supplierCreate($terminal, $user, $payload),
            self::TYPE_SUPPLIER_TRANSACTION => $this->supplierTransaction($terminal, $user, $payload),
            self::TYPE_DELIVERY_CREATE => $this->deliveryCreate($terminal, $user, $payload),
            self::TYPE_STOCK_MOVEMENT => $this->stockMovement($terminal, $user, $payload),
            self::TYPE_CASH_INCOME => $this->cashIncome($terminal, $user, $payload),
            self::TYPE_CASH_EXPENSE => $this->cashExpense($terminal, $user, $payload),
            default => throw new BusinessException('Nomalum amal turi: '.$type),
        };
    }

    // ─── Sales ───

    private function saleCreate(
        PosTerminal $terminal,
        User $user,
        array $payload,
        ?Carbon $occurredAt,
    ): array {
        $data = $this->sales->create($terminal, $user, $payload, $occurredAt);

        return [
            'data' => $data,
            // Linked so pos_operations can answer "which sale did this
            // operation become?". Without it a support request holding only a
            // client_operation_id — which is all the till knows — dead-ends.
            'entity' => Debt::query()->find($data['sale_id']),
        ];
    }

    private function saleCancel(PosTerminal $terminal, User $user, array $payload): array {
        $saleId = (int) ($payload['sale_id'] ?? $payload['debt_id'] ?? 0);

        if ($saleId <= 0) {
            throw ValidationException::withMessages(['sale_id' => ['sale_id talab qilinadi']]);
        }

        return [
            'data' => $this->sales->cancel($terminal, $user, $saleId),
            'entity' => Debt::query()->find($saleId),
        ];
    }

    // ─── Catalogue ───

    private function productCreate(PosTerminal $terminal, User $user, array $payload): array {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Mahsulot nomi talab qilinadi']]);
        }

        $barcode = $this->normalizeBarcode($payload['barcode'] ?? null);

        // Barcodes are unique per shop (see the split_product_code_and_barcode
        // migration). A till that scans an item already in the catalogue must
        // update it, not fork a second row that splits the stock in two.
        if ($barcode !== null) {
            $existing = Product::query()
                ->where('shop_id', $terminal->shop_id)
                ->where('barcode', $barcode)
                ->first();

            if ($existing !== null) {
                return $this->productUpdate($terminal, ['id' => $existing->id] + $payload);
            }
        }

        // One transaction, because a retry after a failure re-runs this handler
        // (see PosIdempotencyService). A product created without its opening
        // movement would survive the failure and the retry would then find no
        // barcode match for an unbarcoded item and create a second row.
        $product = DB::transaction(function () use ($terminal, $user, $name, $barcode, $payload) {
            $product = Product::create([
                'shop_id' => $terminal->shop_id,
                'user_id' => $user->id,
                'name' => $name,
                'barcode' => $barcode,
                // `code` is left blank on purpose — the model's creating hook
                // generates the internal P-XXXXXX reference, same as every other
                // creation path.
                'price' => isset($payload['price']) ? (float) $payload['price'] : null,
                'unit_id' => $payload['unit_id'] ?? null,
                'currency_id' => $payload['currency_id'] ?? null,
                'supplier_id' => $payload['supplier_id'] ?? null,
                'low_stock_threshold' => $payload['low_stock_threshold'] ?? null,
            ]);

            // An opening quantity is a stock movement, never a column write.
            // Writing products.quantity directly would put the cache and the
            // ledger into disagreement from the product's first second of life.
            $opening = isset($payload['quantity']) ? (float) $payload['quantity'] : null;

            if ($opening !== null && $opening != 0.0) {
                $this->stockService->record(
                    $product,
                    StockMovement::TYPE_OPENING,
                    $opening,
                    note: 'POS: boshlang\'ich qoldiq ('.$terminal->name.')',
                    createdById: $user->id,
                );
            }

            return $product;
        });

        return [
            'data' => $this->productArray($product->refresh()),
            'entity' => $product,
        ];
    }

    private function productUpdate(PosTerminal $terminal, array $payload): array {
        $product = $this->findProduct($terminal, (int) ($payload['id'] ?? 0));

        $changes = [];

        // array_key_exists, not ??: an absent key must leave the value alone
        // while an explicit null must clear it. A till syncing a partial edit
        // would otherwise wipe fields it never touched.
        foreach (['name', 'price', 'unit_id', 'currency_id', 'supplier_id', 'low_stock_threshold', 'title', 'description'] as $field) {
            if (array_key_exists($field, $payload)) {
                $changes[$field] = $payload[$field];
            }
        }

        if (array_key_exists('barcode', $payload)) {
            $barcode = $this->normalizeBarcode($payload['barcode']);

            $clash = $barcode === null ? null : Product::query()
                ->where('shop_id', $terminal->shop_id)
                ->where('barcode', $barcode)
                ->whereKeyNot($product->id)
                ->first();

            if ($clash !== null) {
                throw ValidationException::withMessages([
                    'barcode' => ["Bu barcode boshqa mahsulotga biriktirilgan: {$clash->name}"],
                ]);
            }

            $changes['barcode'] = $barcode;
        }

        if ($changes !== []) {
            $product->update($changes);
        }

        return [
            'data' => $this->productArray($product->refresh()),
            'entity' => $product,
        ];
    }

    private function clientCreate(PosTerminal $terminal, User $user, array $payload): array {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Mijoz nomi talab qilinadi']]);
        }

        $client = Client::create([
            'shop_id' => $terminal->shop_id,
            'name' => $name,
            'phone_number' => $payload['phone_number'] ?? null,
            'address' => $payload['address'] ?? null,
            'created_by' => $user->id,
        ]);

        return [
            'data' => [
                'id' => $client->id,
                'name' => $client->name,
                'phone_number' => $client->phone_number,
                'address' => $client->address,
                'updated_at' => $client->updated_at?->toIso8601String(),
            ],
            'entity' => $client,
        ];
    }

    private function supplierCreate(PosTerminal $terminal, User $user, array $payload): array {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Ta\'minotchi nomi talab qilinadi']]);
        }

        $supplier = Supplier::create([
            'shop_id' => $terminal->shop_id,
            'name' => $name,
            'phone_number' => $payload['phone_number'] ?? null,
            'address' => $payload['address'] ?? null,
            'created_by_id' => $user->id,
        ]);

        return [
            'data' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone_number' => $supplier->phone_number,
                'updated_at' => $supplier->updated_at?->toIso8601String(),
            ],
            'entity' => $supplier,
        ];
    }

    /**
     * Money moved with a supplier — chiqim (we paid them) or kirim (they
     * credited us). Delegates to the existing service so the supplier balance
     * recompute and the Kassa mirror behave exactly as they do from the app.
     */
    private function supplierTransaction(PosTerminal $terminal, User $user, array $payload): array {
        $direction = (string) ($payload['direction'] ?? 'chiqim');
        $amount = (float) ($payload['amount'] ?? 0);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Summa 0 dan katta bo\'lishi kerak']]);
        }

        $data = [
            'supplier_id' => (int) ($payload['supplier_id'] ?? 0),
            'currency_id' => $payload['currency_id'] ?? null,
            'amount' => $amount,
            'description' => $payload['description'] ?? null,
            'payment_method' => $payload['payment_method'] ?? null,
        ];

        $transaction = $direction === 'kirim'
            ? $this->supplierTransactions->createIncomeTransaction($user, $terminal->shop, $data)
            : $this->supplierTransactions->createTransaction($user, $terminal->shop, $data);

        return [
            'data' => [
                'id' => $transaction->id,
                'direction' => $direction,
                'supplier_id' => $transaction->supplier_id,
                'amount' => (float) $transaction->amount,
                'currency_id' => $transaction->currency_id,
                'created_at' => $transaction->created_at?->toIso8601String(),
            ],
            'entity' => $transaction,
        ];
    }

    /**
     * Prixod — goods arriving from a supplier. Runs the app's own delivery use
     * case, which creates the invoice, finds-or-creates each product and writes
     * `purchase` movements pointing at the invoice lines.
     */
    private function deliveryCreate(PosTerminal $terminal, User $user, array $payload): array {
        $invoice = $this->delivery->execute($terminal->shop, $user, $payload);

        return [
            'data' => [
                'id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'currency_id' => $invoice->currency_id,
                'total_amount' => (float) $invoice->total_amount,
                'items' => $invoice->items->map(fn ($i) => [
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'quantity' => (float) $i->quantity,
                    'price' => (float) $i->price,
                ])->values()->all(),
                'created_at' => $invoice->created_at?->toIso8601String(),
            ],
            'entity' => $invoice,
        ];
    }

    // ─── Stock ───

    /**
     * A manual movement: adjustment, customer return, write-off, or a stocktake
     * count. Mirrors StockMovementController's rules, including that the SIGN
     * comes from the type and never from the client — a till must not be able
     * to turn a write-off into a stock increase by sending a negative number.
     */
    private function stockMovement(PosTerminal $terminal, User $user, array $payload): array {
        $product = $this->findProduct($terminal, (int) ($payload['product_id'] ?? 0));

        $this->assertShopPermission($user, $terminal, 'manage-stock');

        $counted = $payload['counted_quantity'] ?? null;
        $type = $payload['type'] ?? null;

        if (($counted !== null) === ($type !== null)) {
            throw ValidationException::withMessages([
                'type' => ['counted_quantity (inventarizatsiya) yoki type + quantity yuboring, ikkalasini emas'],
            ]);
        }

        if ($counted !== null) {
            $movement = $this->stockService->recordStocktake(
                $product,
                (float) $counted,
                note: $payload['note'] ?? 'POS inventarizatsiya',
                createdById: $user->id,
            );
        } else {
            $allowed = [StockMovement::TYPE_ADJUSTMENT, StockMovement::TYPE_RETURN, StockMovement::TYPE_WRITE_OFF];

            if (! in_array($type, $allowed, true)) {
                throw ValidationException::withMessages([
                    'type' => ['Ruxsat etilgan turlar: '.implode(', ', $allowed)],
                ]);
            }

            $magnitude = abs((float) ($payload['quantity'] ?? 0));

            if ($magnitude <= 0) {
                throw ValidationException::withMessages(['quantity' => ['Miqdor 0 dan katta bo\'lishi kerak']]);
            }

            $signed = $type === StockMovement::TYPE_WRITE_OFF ? -$magnitude : $magnitude;

            $movement = $this->stockService->recordManual(
                $product,
                $type,
                $signed,
                allowNegative: true,
                note: $payload['note'] ?? null,
                createdById: $user->id,
            );
        }

        return [
            'data' => [
                'id' => $movement?->id,
                'product_id' => $product->id,
                'type' => $movement?->type,
                'quantity' => $movement ? (float) $movement->quantity : 0.0,
                'current_stock' => $this->stockService->currentStock($product),
            ],
            'entity' => $movement,
        ];
    }

    // ─── Kassa ───

    private function cashIncome(PosTerminal $terminal, User $user, array $payload): array {
        $income = ShopIncome::create([
            'shop_id' => $terminal->shop_id,
            'amount' => $this->positiveAmount($payload),
            'currency_id' => $payload['currency_id'] ?? null,
            'shop_income_category_id' => $this->cashCategories->income($terminal->shop_id, $payload['category_id'] ?? null),
            'description' => $payload['description'] ?? null,
            'payment_type' => $this->paymentType($payload),
            'created_by_id' => $user->id,
        ]);

        return [
            'data' => [
                'id' => $income->id,
                'amount' => (float) $income->amount,
                'currency_id' => $income->currency_id,
                'created_at' => $income->created_at?->toIso8601String(),
            ],
            'entity' => $income,
        ];
    }

    private function cashExpense(PosTerminal $terminal, User $user, array $payload): array {
        $expense = ShopExpense::create([
            'shop_id' => $terminal->shop_id,
            'amount' => $this->positiveAmount($payload),
            'currency_id' => $payload['currency_id'] ?? null,
            'shop_expense_category_id' => $this->cashCategories->expense($terminal->shop_id, $payload['category_id'] ?? null),
            'description' => $payload['description'] ?? null,
            'payment_type' => $this->paymentType($payload),
            'created_by_id' => $user->id,
        ]);

        return [
            'data' => [
                'id' => $expense->id,
                'amount' => (float) $expense->amount,
                'currency_id' => $expense->currency_id,
                'created_at' => $expense->created_at?->toIso8601String(),
            ],
            'entity' => $expense,
        ];
    }

    // ─── Shared helpers ───

    /**
     * Swap `{"$local": "uuid"}` references for the server ids created earlier in
     * this batch.
     *
     * A till that creates a product offline and immediately sells it has no
     * server id for it — the sale must be able to say "the thing I created two
     * operations ago". Without this, an offline session's first sale of a new
     * product is unsendable until a second round trip.
     */
    private function resolveLocalRefs(mixed $value, array $map): mixed {
        if (is_array($value)) {
            if (isset($value['$local']) && is_string($value['$local'])) {
                return $map[$value['$local']] ?? $value;
            }

            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->resolveLocalRefs($v, $map);
            }

            return $out;
        }

        return $value;
    }

    private function findProduct(PosTerminal $terminal, int $id): Product {
        /** @var Product|null $product */
        $product = Product::query()
            ->where('shop_id', $terminal->shop_id)
            ->whereKey($id)
            ->first();

        if ($product === null) {
            throw ValidationException::withMessages([
                'product_id' => ["Mahsulot topilmadi yoki bu do'konga tegishli emas (id: {$id})"],
            ]);
        }

        return $product;
    }

    /**
     * The token's scope said the TILL may do this. This says the USER may.
     *
     * Both are required and neither replaces the other: a seller without
     * manage-stock must not gain it by holding a terminal token, and a token
     * minted without stock scope must not write stock even for an owner.
     */
    private function assertShopPermission(User $user, PosTerminal $terminal, string $permission): void {
        if (! $this->permissions->allows($user, $terminal->shop, $permission)) {
            throw new BusinessException('Sizda bu amal uchun do\'kon ruxsati yo\'q: '.$permission);
        }
    }

    private function positiveAmount(array $payload): float {
        $amount = round((float) ($payload['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Summa 0 dan katta bo\'lishi kerak']]);
        }

        return $amount;
    }

    private function paymentType(array $payload): string {
        $type = $payload['payment_type'] ?? ShopExpensePaymentTypeEnum::CASH->value;

        if (! in_array($type, ShopExpensePaymentTypeEnum::values(), true)) {
            throw ValidationException::withMessages([
                'payment_type' => ['To\'lov turi noto\'g\'ri: '.implode(', ', ShopExpensePaymentTypeEnum::values())],
            ]);
        }

        return $type;
    }

    private function normalizeBarcode(mixed $barcode): ?string {
        if ($barcode === null) {
            return null;
        }

        // Scanners emit trailing newlines and stray whitespace; a code that
        // differs only by that is the same code (same rule as the app's own
        // scan lookup).
        $trimmed = trim((string) $barcode);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseOccurredAt(mixed $value): ?Carbon {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function productArray(Product $product): array {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'barcode' => $product->barcode,
            'price' => $product->price === null ? null : (float) $product->price,
            'quantity' => $product->quantity === null ? null : (float) $product->quantity,
            'unit_id' => $product->unit_id,
            'currency_id' => $product->currency_id,
            'supplier_id' => $product->supplier_id,
            'low_stock_threshold' => $product->low_stock_threshold === null ? null : (float) $product->low_stock_threshold,
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
