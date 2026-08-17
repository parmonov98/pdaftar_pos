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
use App\Models\ShopIncome;
use App\Models\User;
use App\Services\Stock\StockService;
use App\UseCases\Debt\CancelDebtUseCase;
use App\UseCases\Debt\StoreDebtUseCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pos\Models\PosOperation;
use Pos\Models\PosTerminal;

/**
 * A till sale. Two shapes, because a POS has a case pDaftar does not.
 *
 * pDaftar's Sotuv is a wholesaler's calculator: if the customer pays cash the
 * seller never saves anything, so everything that IS saved is unpaid, and
 * writing it to the debtor is right. A counter POS also has the ordinary sale —
 * paid and done — and there is no debtor to write it to.
 *
 * So:
 *
 *   PAID IN FULL  → frozen SalesCalcList + lines, a ShopIncome in Kassa, and
 *                   `sale` movements in the stock ledger. No debt, because
 *                   nobody owes anything.
 *
 *   NASIYA        → frozen SalesCalcList + lines, then Debt through pDaftar's
 *   (or partial)    own StoreDebtUseCase, untouched. When the client settles up
 *                   they do it in pDaftar, which writes the Repayment and
 *                   mirrors it into Kassa then.
 *
 * The nasiya path deliberately reimplements nothing: stock, client balance, SMS
 * and the sales quota all stay inside the shared use case. A POS that decremented
 * stock with its own copy of that logic would be correct on the day it shipped
 * and silently wrong the first time pDaftar's sale flow changed.
 *
 * Both paths write the frozen calc list, so the line items of every sale are
 * queryable in one place regardless of how it was paid — Kassa's `description`
 * is for a human to read, not to report on.
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
        private readonly PosCashCategoryResolver $cashCategories,
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

        $isWalkIn = $this->isWalkIn($client);
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

        // ─── Paid in full: this is a counter sale, not a debt ───
        //
        // pDaftar's Sotuv only ever writes debts, and that is correct for what it
        // is: a wholesaler's calculator, where nothing gets saved unless the goods
        // went out unpaid. A POS has the case pDaftar does not — the customer
        // pays and leaves owing nothing — and forcing that through `debts` would
        // put a row on a debtor's page for a transaction that was settled at the
        // counter.
        //
        // So a paid sale goes straight to Kassa as income, which is where the
        // money actually is. Unconditionally: the repayment mirror's
        // `kassa_sync_repayments_enabled` toggle governs money arriving against
        // OLD debts, and gating counter revenue behind it is why a paid POS sale
        // was previously landing nowhere at all in most shops.
        if (! $this->isCredit($total, $paidAmount)) {
            return $this->recordPaidSale(
                $terminal,
                $user,
                $shop,
                $lines,
                $listId,
                [
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'total' => $total,
                    'paid' => $paidAmount ?? $total,
                    'change' => $change,
                    'currency_id' => $currencyId,
                    'payment_type' => $paymentType ?? ShopExpensePaymentTypeEnum::CASH->value,
                    'note' => $payload['note'] ?? null,
                ],
                $client,
                $isWalkIn,
                $occurredAt,
            );
        }

        // ─── Not paid in full: nasiya, exactly as pDaftar records it ───
        //
        // Debt via the shared use case, untouched. When the client later settles
        // up they do it in pDaftar, which writes the Repayment and mirrors it to
        // Kassa then — that half is deliberately none of the POS's business.
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
            'kind' => 'debt',
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
     * A settled counter sale: money into Kassa, stock out of the ledger, no debt.
     *
     * @param  list<array{product: Product, quantity: float, price: float, total: float, name: string}>  $lines
     * @param  array<string, mixed>  $money
     * @return array<string, mixed>
     */
    private function recordPaidSale(
        PosTerminal $terminal,
        User $user,
        Shop $shop,
        array $lines,
        int $listId,
        array $money,
        Client $client,
        bool $isWalkIn,
        ?Carbon $occurredAt,
    ): array {
        $date = $this->businessDate($occurredAt) ?? now()->format('Y-m-d H:i:s');

        try {
            $income = DB::transaction(function () use ($user, $shop, $lines, $listId, $money, $client, $isWalkIn, $date) {
                $income = ShopIncome::create([
                    'shop_id' => $shop->id,
                    'amount' => $money['total'],
                    'currency_id' => $money['currency_id'],
                    'shop_income_category_id' => $this->cashCategories->posSales((int) $shop->id),
                    'description' => $this->saleDescription($lines, $money, $client, $isWalkIn),
                    'payment_type' => $money['payment_type'],
                    'created_by_id' => $user->id,
                    // `shop_incomes` has no transaction-date column of its own —
                    // created_at IS the date the Kassa list groups and totals by
                    // (see ShopIncomeSyncService). An offline sale synced two days
                    // late must therefore carry the day it happened, or Kassa's
                    // "Bugun" is over by it and the real day is short.
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);

                // Stock is attributed to the frozen calc list rather than to the
                // income row: the list holds the line items, so a reversal and the
                // "what price did this go out at?" lookup both have something to
                // read. The income row is money, and money does not know what a
                // kilogram of anything cost.
                $list = SalesCalcList::find($listId);

                foreach ($lines as $line) {
                    $this->stockService->recordSale(
                        $line['product'],
                        $line['quantity'],
                        // A till reports what already happened — see the note on
                        // StoreDebtUseCase::$forceAllowNegativeStock.
                        allowNegative: true,
                        source: $list,
                        createdById: $user->id,
                    );
                }

                return $income;
            });
        } catch (\Throwable $e) {
            SalesCalcList::whereKey($listId)->delete();
            SalesCalcItem::where('sales_calc_list_id', $listId)->forceDelete();

            throw $e;
        }

        return [
            'kind' => 'income',
            'sale_id' => $income->id,
            'income_id' => $income->id,
            'debt_id' => null,
            'sales_calc_list_id' => $listId,
            'client_id' => $isWalkIn ? null : $client->id,
            'client_name' => $isWalkIn ? null : $client->name,
            'currency_id' => $money['currency_id'],
            'subtotal' => $money['subtotal'],
            'discount_amount' => $money['discount'],
            'total' => $money['total'],
            'paid_amount' => $money['paid'],
            'payment_type' => $money['payment_type'],
            'change' => $money['change'],
            'is_credit' => false,
            'created_at' => Carbon::parse($date)->toIso8601String(),
            'stock' => $this->stockSnapshot($lines),
        ];
    }

    /**
     * What the shopkeeper reads in Kassa.
     *
     * `shop_incomes` has no line-item table — only this free-text field — so the
     * products go in here or they are not visible in Kassa at all. The structured
     * copy still exists on the frozen calc list for anything that needs to query
     * it; this is the human-readable half.
     *
     * @param  list<array{product: Product, quantity: float, price: float, total: float, name: string}>  $lines
     * @param  array<string, mixed>  $money
     */
    private function saleDescription(array $lines, array $money, Client $client, bool $isWalkIn): string {
        $items = array_map(
            fn (array $l) => trim(($l['name'] !== '' ? $l['name'] : '#'.$l['product']->id)
                .' × '.$this->formatNumber($l['quantity'])),
            $lines,
        );

        $parts = [implode(', ', $items)];

        if ($money['discount'] > 0) {
            $parts[] = 'Chegirma: '.number_format((float) $money['discount'], 0, '.', ' ');
        }

        // Optional by design: most counter sales are a stranger paying cash, and
        // writing the house account's name on every one of them would be noise.
        if (! $isWalkIn) {
            $parts[] = 'Mijoz: '.$client->name;
        }

        if (filled($money['note'] ?? null)) {
            $parts[] = (string) $money['note'];
        }

        return implode(' · ', $parts);
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
    public function cancel(PosTerminal $terminal, User $user, int $saleId, ?string $kind = null): array {
        // A paid sale and a nasiya sale live in different tables and their ids
        // are independent sequences, so the caller says which. `kind` comes
        // straight from the sale response the till stored; the fallback tries
        // Kassa first because that is the common case at a counter.
        $tryIncome = $kind === null || $kind === 'income';
        $tryDebt = $kind === null || $kind === 'debt';

        if ($tryIncome) {
            /** @var ShopIncome|null $income */
            $income = ShopIncome::query()
                ->where('shop_id', $terminal->shop_id)
                ->whereKey($saleId)
                ->first();

            if ($income !== null) {
                return $this->cancelPaidSale($terminal, $income);
            }
        }

        if ($tryDebt) {
            /** @var Debt|null $debt */
            $debt = Debt::query()->find($saleId);

            if ($debt !== null && (int) $debt->shop_id === (int) $terminal->shop_id) {
                $this->cancelDebt->execute($debt->id, $user);

                return [
                    'kind' => 'debt',
                    'sale_id' => $debt->id,
                    'cancelled' => true,
                    'stock' => $this->stockFor($this->productIdsOfList((int) $debt->sales_calc_list_id)),
                ];
            }
        }

        throw new BusinessException('Sotuv topilmadi');
    }

    /**
     * Undo a paid counter sale: the Kassa income goes, the stock comes back.
     *
     * The income is soft-deleted rather than hard, so a cancelled sale is still
     * traceable — a till that cancels the wrong receipt is a support question,
     * and a row that vanished answers nothing.
     *
     * @return array<string, mixed>
     */
    private function cancelPaidSale(PosTerminal $terminal, ShopIncome $income): array {
        // `shop_incomes` has no column pointing at the calc list, but the POS's
        // own operation log does: the stored response of the sale that created
        // this income carries `sales_calc_list_id`. Exact, and POS-owned — no
        // guessing by timestamp and no new column on a pDaftar table.
        $listId = $this->listIdOfIncome($income);

        if ($listId === null) {
            throw new BusinessException(
                'Bu kirim POS sotuvi emas yoki uning qatorlari topilmadi — Kassadan qo\'lda o\'chiring.'
            );
        }

        $productIds = $this->productIdsOfList($listId);

        DB::transaction(function () use ($income, $listId) {
            $list = SalesCalcList::find($listId);

            // reverseFor DELETES the movements that caused the change rather
            // than writing compensating ones, so the product's history reads as
            // if the sale never happened instead of as a purchase the shop
            // never made.
            if ($list !== null) {
                $this->stockService->reverseFor($list);
            }

            // Soft delete: a cancelled sale must stay traceable. A till that
            // voided the wrong receipt is a support question, and a row that
            // vanished answers nothing.
            $income->delete();
        });

        return [
            'kind' => 'income',
            'sale_id' => $income->id,
            'cancelled' => true,
            'stock' => $this->stockFor($productIds),
        ];
    }

    private function listIdOfIncome(ShopIncome $income): ?int {
        $response = PosOperation::query()
            ->where('shop_id', $income->shop_id)
            ->where('type', 'sale.create')
            ->where('entity_type', ShopIncome::class)
            ->where('entity_id', $income->id)
            ->value('response');

        $listId = is_array($response) ? ($response['sales_calc_list_id'] ?? null) : null;

        return is_numeric($listId) ? (int) $listId : null;
    }

    /** @return list<int> */
    private function productIdsOfList(int $listId): array {
        return SalesCalcItem::query()
            ->where('sales_calc_list_id', $listId)
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $productIds
     * @return list<array{product_id: int, quantity: float|null}>
     */
    private function stockFor(array $productIds): array {
        $stock = $this->stockService->stockFor($productIds);

        return array_map(
            fn (int $id) => ['product_id' => $id, 'quantity' => $stock[$id] ?? null],
            $productIds,
        );
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

            // The house account is reachable by id like any other client, so the
            // "nasiya needs a real client" rule has to be checked here too and
            // not only on the no-client-given path. A credit sale booked against
            // it is a receivable with nobody to collect from.
            if ($isCredit && $this->isWalkIn($client)) {
                throw ValidationException::withMessages([
                    'client_id' => ['Nasiya sotuvni "'.PosWalkInClientResolver::NAME.'" hisobiga yozib bo\'lmaydi — haqiqiy mijoz tanlang'],
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

    /** The shop's anonymous cash-sale account, not a customer anyone chose. */
    private function isWalkIn(Client $client): bool {
        return $client->name === PosWalkInClientResolver::NAME && $client->phone_number === null;
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
