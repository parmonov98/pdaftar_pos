<?php

declare(strict_types=1);

namespace Pos\Services;

use App\Constants\ShopExpense\ShopExpensePaymentTypeEnum;
use App\DTO\Debt\StoreDebtDTO;
use App\Exceptions\Custom\BusinessException;
use App\Models\Client;
use App\Models\Debt;
use App\Models\Product;
use App\Models\SalesCalcItem;
use App\Models\SalesCalcList;
use App\Models\Shop;
use App\Models\User;
use App\Services\Stock\StockService;
use App\UseCases\Debt\CancelDebtUseCase;
use App\UseCases\Debt\StoreDebtUseCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pos\Models\PosTerminal;

/**
 * A till sale, written exactly where the mobile app writes one.
 *
 * This service builds the same three things a Sotuv in the app builds, in the
 * same order, through the same use case:
 *
 *   SalesCalcList (frozen)  →  SalesCalcItem lines  →  Debt via StoreDebtUseCase
 *
 * and StoreDebtUseCase does the rest — `sale` rows in the stock ledger attributed
 * to the Debt, a Repayment for whatever was handed over, the Kassa Kirim mirror,
 * the client balance recompute, the sales quota.
 *
 * Reimplementing any of that here is the one thing that must never happen. A POS
 * sale that decremented stock with its own code would be correct on the day it
 * shipped and silently wrong the first time the app's sale flow changed — and
 * the two would disagree in the reports long before anyone noticed. Going
 * through the use case means a POS sale and an app sale are indistinguishable in
 * the database, which is the whole promise of the integration.
 */
class PosSaleService {
    /**
     * How far back a till may date a sale. Longer than any realistic offline
     * stretch (a shop without internet for a fortnight has bigger problems) and
     * short enough that a broken clock cannot reach into a closed month.
     */
    private const MAX_BACKDATE_DAYS = 14;

    public function __construct(
        private readonly StoreDebtUseCase $storeDebt,
        private readonly CancelDebtUseCase $cancelDebt,
        private readonly StockService $stockService,
        private readonly PosWalkInClientResolver $walkIn,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws BusinessException
     * @throws ValidationException
     */
    public function create(
        PosTerminal $terminal,
        User $user,
        array $payload,
        ?Carbon $occurredAt = null,
    ): array {
        $shop = $terminal->shop;

        $lines = $this->normalizeLines($shop, $payload['items'] ?? []);

        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => ['Sotuvda kamida bitta mahsulot bo\'lishi kerak'],
            ]);
        }

        $subtotal = array_sum(array_column($lines, 'total'));
        $discount = round((float) ($payload['discount_amount'] ?? 0), 2);

        if ($discount < 0) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Chegirma manfiy bo\'lishi mumkin emas'],
            ]);
        }

        if ($discount > $subtotal) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Chegirma savdo summasidan katta bo\'lishi mumkin emas'],
            ]);
        }

        $total = round($subtotal - $discount, 2);

        if ($total <= 0) {
            throw ValidationException::withMessages([
                'items' => ['Savdo summasi 0 dan katta bo\'lishi kerak'],
            ]);
        }

        $paymentType = $payload['payment_type'] ?? null;
        $paidAmount = isset($payload['paid_amount']) ? round((float) $payload['paid_amount'], 2) : null;

        if ($paymentType !== null && ! in_array($paymentType, ShopExpensePaymentTypeEnum::values(), true)) {
            throw ValidationException::withMessages([
                'payment_type' => ['To\'lov turi noto\'g\'ri: '.implode(', ', ShopExpensePaymentTypeEnum::values())],
            ]);
        }

        // The drawer takes what it takes; anything over the sale total pays down
        // the client's older balance (StoreDebtUseCase handles that). What is
        // NOT allowed is recording change given as if it were revenue, so the
        // repayment is capped at the sale for a walk-in, who by definition has
        // no older balance to overpay into.
        $client = $this->resolveClient($user, $shop, $payload, isCredit: $this->isCredit($total, $paidAmount));

        $isWalkIn = $client->name === PosWalkInClientResolver::NAME && $client->phone_number === null;
        $change = 0.0;

        if ($isWalkIn && $paidAmount !== null && $paidAmount > $total) {
            $change = round($paidAmount - $total, 2);
            $paidAmount = $total;
        }

        if ($paidAmount !== null && $paidAmount > 0 && $paymentType === null) {
            $paymentType = ShopExpensePaymentTypeEnum::CASH->value;
        }

        if ($paymentType !== null && ($paidAmount === null || $paidAmount <= 0)) {
            $paymentType = null;
        }

        $currencyId = (int) ($payload['currency_id'] ?? 0);
        if ($currencyId <= 0) {
            throw ValidationException::withMessages([
                'currency_id' => ['Valyuta ko\'rsatilishi shart'],
            ]);
        }

        $listId = $this->writeSalesCalcSnapshot($shop, $user, $terminal, $lines, $discount, $payload);

        try {
            $debt = $this->storeDebt->execute(
                StoreDebtDTO::fromArray([
                    'base_amount' => $total,
                    'currency_id' => $currencyId,
                    'client_id' => $client->id,
                    'description' => $payload['note'] ?? null,
                    'deadline' => $payload['deadline'] ?? null,
                    'sales_calc_list_id' => $listId,
                    'payment_type' => $paymentType,
                    'paid_amount' => ($paidAmount !== null && $paidAmount > 0) ? $paidAmount : null,
                    'date' => $this->businessDate($occurredAt),
                ]),
                $user,
                $shop,
                // See the parameter's docblock: a till reports sales that already
                // happened, so the stock floor cannot be allowed to reject one.
                forceAllowNegativeStock: true,
            );
        } catch (\Throwable $e) {
            // The snapshot and the debt are two transactions, not one — the use
            // case dispatches jobs after its own commit, and wrapping it would
            // let those jobs run against uncommitted rows. So the orphan is
            // cleaned up explicitly instead: without this, a till retrying a
            // failed sale would leave one dead frozen list per attempt, each
            // holding product_id + quantity lines that read like a real sale to
            // anyone querying the table directly.
            SalesCalcList::whereKey($listId)->delete();
            SalesCalcItem::where('sales_calc_list_id', $listId)->forceDelete();

            throw $e;
        }

        return [
            'sale_id' => $debt->id,
            'debt_id' => $debt->id,
            'sales_calc_list_id' => $listId,
            'client_id' => $client->id,
            'client_name' => $client->name,
            'currency_id' => $currencyId,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total' => $total,
            'paid_amount' => $paidAmount ?? 0.0,
            'payment_type' => $paymentType,
            'change' => $change,
            'is_credit' => $this->isCredit($total, $paidAmount),
            // Debt::getCreatedAtAttribute casts this to a 'Y-m-d H:i:s' STRING,
            // so it is not a Carbon and cannot be formatted like one. Re-parsed
            // rather than passed through, because the rest of this API is
            // ISO8601 and a till parsing two date formats will get one wrong.
            'created_at' => $debt->created_at ? Carbon::parse($debt->created_at)->toIso8601String() : null,
            // The authoritative post-sale figure for every product touched. The
            // till overwrites its local cache with these instead of trusting the
            // number it decremented itself — that is what stops two kassa
            // selling the same shelf into two different beliefs about it.
            'stock' => $this->stockSnapshot($lines),
        ];
    }

    /**
     * Undo a till sale.
     *
     * Delegates to CancelDebtUseCase, which reverses the stock movements via
     * StockService::reverseFor($debt) — deleting the `sale` rows rather than
     * writing compensating ones, so the product's history reads as if the sale
     * never happened instead of as a purchase it never had.
     *
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    public function cancel(PosTerminal $terminal, User $user, int $debtId): array {
        /** @var Debt|null $debt */
        $debt = Debt::query()->find($debtId);

        if ($debt === null || (int) $debt->shop_id !== (int) $terminal->shop_id) {
            throw new BusinessException('Sotuv topilmadi');
        }

        $this->cancelDebt->execute($debt->id, $user);

        $productIds = SalesCalcItem::query()
            ->where('sales_calc_list_id', $debt->sales_calc_list_id)
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->unique()
            ->all();

        $stock = $this->stockService->stockFor(array_map('intval', $productIds));

        return [
            'sale_id' => $debt->id,
            'cancelled' => true,
            'stock' => array_map(
                fn ($id) => ['product_id' => (int) $id, 'quantity' => $stock[$id] ?? null],
                $productIds,
            ),
        ];
    }

    /**
     * Resolve who this sale is for.
     *
     * Nasiya (nothing, or not everything, handed over) MUST name a real client:
     * an unpaid sale is a debt someone owes, and putting it on the house account
     * would create a receivable with nobody to collect from — invisible in the
     * debtors list and unrecoverable later.
     *
     * @throws ValidationException
     */
    private function resolveClient(
        User $user,
        Shop $shop,
        array $payload,
        bool $isCredit,
    ): Client {
        $clientId = $payload['client_id'] ?? null;

        if ($clientId !== null) {
            /** @var Client|null $client */
            $client = Client::query()
                ->where('id', $clientId)
                ->where('shop_id', $shop->id)
                ->first();

            if ($client === null) {
                throw ValidationException::withMessages([
                    'client_id' => ['Mijoz bu do\'konga tegishli emas'],
                ]);
            }

            return $client;
        }

        $inline = $payload['client'] ?? null;

        if (is_array($inline) && filled($inline['name'] ?? null)) {
            return Client::create([
                'shop_id' => $shop->id,
                'name' => $inline['name'],
                'phone_number' => $inline['phone_number'] ?? null,
                'address' => $inline['address'] ?? null,
                'created_by' => $user->id,
            ]);
        }

        if ($isCredit) {
            throw ValidationException::withMessages([
                'client_id' => ['Nasiya sotuv uchun mijoz tanlanishi shart'],
            ]);
        }

        return $this->walkIn->resolve($shop, $user->id);
    }

    private function isCredit(float $total, ?float $paidAmount): bool {
        return ($paidAmount ?? 0.0) < $total;
    }

    /**
     * Turn the till's cart into calc lines the rest of pDaftar understands.
     *
     * Every line is validated against THIS shop's catalogue. Route-model style
     * trust ("the till sent an id, it must be theirs") is how one shop's till
     * ends up decrementing another shop's stock.
     *
     * @return list<array{product: Product, quantity: float, price: float, total: float, name: string}>
     *
     * @throws ValidationException
     */
    private function normalizeLines(Shop $shop, mixed $items): array {
        if (! is_array($items)) {
            return [];
        }

        $ids = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['product_id'])) {
                $ids[] = (int) $item['product_id'];
            }
        }

        /** @var array<int, Product> $products */
        $products = Product::query()
            ->where('shop_id', $shop->id)
            ->whereIn('id', array_unique($ids))
            ->get()
            ->keyBy('id')
            ->all();

        $lines = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $product = $products[$productId] ?? null;

            if ($product === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ["Mahsulot topilmadi yoki bu do'konga tegishli emas (id: {$productId})"],
                ]);
            }

            $quantity = round((float) ($item['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ['Miqdor 0 dan katta bo\'lishi kerak'],
                ]);
            }

            // Price is taken from the till, not the catalogue: the cashier may
            // discount a line, and the shop opted into that. Falls back to the
            // catalogue price only when the till sends none.
            $price = array_key_exists('price', $item) && $item['price'] !== null
                ? round((float) $item['price'], 2)
                : round((float) ($product->price ?? 0), 2);

            if ($price < 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.price" => ['Narx manfiy bo\'lishi mumkin emas'],
                ]);
            }

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'price' => $price,
                'total' => round($quantity * $price, 2),
                'name' => (string) ($product->name ?? ($item['name'] ?? '')),
            ];
        }

        return $lines;
    }

    /**
     * Write the frozen calc list the debt will point at.
     *
     * `is_frozen` matters more than it looks: an unfrozen list shows up as a tab
     * in the mobile Sotuv calculator, so every till sale would drop a phantom
     * tab into the owner's phone. Frozen lists are exactly what the app's own
     * save-as-debt flow creates (see SalesCalcItemController::listClone).
     *
     * @param  list<array{product: Product, quantity: float, price: float, total: float, name: string}>  $lines
     */
    private function writeSalesCalcSnapshot(
        Shop $shop,
        User $user,
        PosTerminal $terminal,
        array $lines,
        float $discount,
        array $payload,
    ): int {
        return DB::transaction(function () use ($shop, $user, $terminal, $lines, $discount, $payload) {
            $list = SalesCalcList::create([
                'shop_id' => $shop->id,
                'name' => $payload['receipt_no'] ?? ($terminal->name.' '.now()->format('d.m H:i')),
                'is_frozen' => true,
                'show_labels' => true,
                'created_by_id' => $user->id,
            ]);

            $position = 0;

            foreach ($lines as $line) {
                SalesCalcItem::create([
                    'shop_id' => $shop->id,
                    'sales_calc_list_id' => $list->id,
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    // `qty*price` is the form the rest of the codebase parses a
                    // unit price out of (SalesCalcItemController::extractUnitPrice,
                    // the stock history's counterparty lookup). Writing the total
                    // here instead would make every POS line read back as costing
                    // the whole line amount per unit.
                    'expression' => $this->formatNumber($line['quantity']).'*'.$this->formatNumber($line['price']),
                    'label' => $line['name'],
                    'result' => $line['total'],
                    'position' => $position++,
                    'created_by_id' => $user->id,
                ]);
            }

            if ($discount > 0) {
                // Carried as its own negative line so the list's items sum to
                // the debt's base_amount. If the discount were only applied to
                // the total, every receipt would print lines that add up to
                // more than the customer paid.
                SalesCalcItem::create([
                    'shop_id' => $shop->id,
                    'sales_calc_list_id' => $list->id,
                    'product_id' => null,
                    'quantity' => null,
                    'expression' => '-'.$this->formatNumber($discount),
                    'label' => 'Chegirma',
                    'result' => -$discount,
                    'position' => $position,
                    'created_by_id' => $user->id,
                ]);
            }

            return $list->id;
        });
    }

    /**
     * @param  list<array{product: Product, quantity: float, price: float, total: float, name: string}>  $lines
     * @return list<array{product_id: int, quantity: float|null}>
     */
    private function stockSnapshot(array $lines): array {
        $ids = array_values(array_unique(array_map(fn ($l) => $l['product']->id, $lines)));
        $stock = $this->stockService->stockFor($ids);

        return array_map(
            fn (int $id) => ['product_id' => $id, 'quantity' => $stock[$id] ?? null],
            $ids,
        );
    }

    /**
     * The date this sale belongs to in the books.
     *
     * A till that was offline reports when the cashier actually rang the sale
     * up, and that is the date the money moved — Friday's takings must include
     * Friday's sales even if the device only reconnected on Sunday.
     *
     * But the timestamp comes from a device clock, and device clocks are wrong
     * in ways that are not subtle: a tablet that lost power can come back in
     * 1970, and a manually-set one can sit days ahead. Two guards, both
     * deliberately falling back to NOW rather than trusting the device:
     *
     *  - a FUTURE date is always wrong, and would park revenue in a period that
     *    has not happened yet where nobody reviewing today's numbers will see it;
     *  - anything older than the window below is not a plausible offline gap,
     *    it is a broken clock, and back-dating a sale into a closed month
     *    silently changes a figure somebody already reported.
     *
     * Rejected timestamps do not lose the sale — it is recorded today, and the
     * device's own `occurred_at` stays on the pos_operations row for anyone
     * investigating.
     */
    private function businessDate(?Carbon $occurredAt): ?string {
        if ($occurredAt === null) {
            return null;
        }

        $now = Carbon::now();

        // Small forward skew is normal clock drift, not a claim about the future.
        if ($occurredAt->greaterThan($now->copy()->addMinutes(5))) {
            return null;
        }

        if ($occurredAt->lessThan($now->copy()->subDays(self::MAX_BACKDATE_DAYS))) {
            return null;
        }

        return $occurredAt->format('Y-m-d H:i:s');
    }

    /** Trim trailing zeros so `2.00*18000.00` reads as `2*18000`. */
    private function formatNumber(float $value): string {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
