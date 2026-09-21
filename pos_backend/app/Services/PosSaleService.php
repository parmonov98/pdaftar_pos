<?php

declare(strict_types=1);

namespace Pos\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pos\Exceptions\BusinessException;
use Pos\Models\Client;
use Pos\Models\PosTerminal;
use Pos\Models\Product;
use Pos\Models\ProductPrice;
use Pos\Models\Sale;
use Pos\Models\SaleItem;

/**
 * Ringing up a sale.
 *
 * The POS's own — it writes the POS's own tables and asks pDaftar nothing.
 *
 * One rule shapes most of what follows: **a sale that physically happened is
 * never refused.** The cash is in the drawer and the goods are in the
 * customer's bag by the time this code runs. Refusing to record it does not
 * un-sell it; it only loses the record.
 */
class PosSaleService {
    public function __construct(
        private readonly PosStockService $stock,
        private readonly PosPricingService $pricing,
    ) {}

    /**
     * @param  array{
     *     currency_id?: int|null, discount_amount?: float|null, payment_type?: string|null,
     *     paid_amount?: float|null, note?: string|null,
     *     items: array<int, array{product_id: int, quantity: float, price: float}>
     * }  $payload
     *
     * @throws BusinessException
     */
    public function create(PosTerminal $terminal, array $payload, ?Carbon $occurredAt, int $userId): Sale {
        $lines = $payload['items'] ?? [];

        if ($lines === []) {
            throw new BusinessException('Savatda mahsulot yo\'q');
        }

        $at = $occurredAt ?? now();

        return DB::transaction(function () use ($terminal, $payload, $lines, $at, $userId) {
            $products = Product::query()
                ->where('shop_id', $terminal->shop_id)
                ->whereIn('id', array_column($lines, 'product_id'))
                ->get()
                ->keyBy('id');

            $subtotal = 0.0;
            $prepared = [];

            foreach ($lines as $line) {
                $product = $products->get($line['product_id'] ?? 0);

                if ($product === null) {
                    // This one IS refused: a line naming a product this shop
                    // does not have is a client bug, and guessing which
                    // product was meant would write a sale nobody made.
                    throw new BusinessException('Mahsulot topilmadi: #'.($line['product_id'] ?? '?'));
                }

                $quantity = (float) ($line['quantity'] ?? 0);

                if ($quantity <= 0) {
                    throw new BusinessException($product->name.': miqdor noldan katta bo\'lishi kerak');
                }

                // Which unit this line is in — a box, a bottle, a kilo.
                $productUnit = $this->pricing->resolveUnit($product, $line['product_unit_id'] ?? null);

                // The till may send a price (the cashier overrode it, or it
                // was chosen offline from the cached catalogue). When it does
                // not, resolve one — and refuse rather than invent, because a
                // guessed price is money lost quietly on every line.
                $price = array_key_exists('price', $line) && $line['price'] !== null
                    ? (float) $line['price']
                    : $this->pricing->priceFor(
                        $product,
                        $productUnit,
                        (int) ($payload['currency_id'] ?? $product->currency_id),
                        ProductPrice::TYPE_SALE,
                    );

                if ($price === null) {
                    throw new BusinessException($product->name.': bu birlik va valyuta uchun narx belgilanmagan');
                }

                $total = round($quantity * $price, 6);
                $subtotal += $total;

                $prepared[] = [$product, $quantity, (float) $price, $total, $productUnit];
            }

            $discount = round((float) ($payload['discount_amount'] ?? 0), 6);
            $discount = max(0.0, min($discount, $subtotal));
            $total = round($subtotal - $discount, 6);

            // A sale that is not paid in full is a debt, and a debt with
            // nobody attached to it is money the shop cannot chase. Refused
            // here rather than written and discovered at the end of the month.
            $paid = round((float) ($payload['paid_amount'] ?? 0), 6);
            $clientId = $payload['client_id'] ?? null;

            if ($paid + 0.000001 < $total && $clientId === null) {
                throw new BusinessException(
                    'To\'liq to\'lanmagan sotuv nasiya hisoblanadi — mijoz tanlang',
                );
            }

            if ($clientId !== null && ! Client::query()
                ->where('shop_id', $terminal->shop_id)
                ->whereKey($clientId)
                ->exists()) {
                throw new BusinessException('Mijoz topilmadi: #'.$clientId);
            }

            $sale = Sale::create([
                'shop_id' => $terminal->shop_id,
                'pos_terminal_id' => $terminal->id,
                // Set only when the till split one basket across currencies.
                'sale_group_id' => $payload['sale_group_id'] ?? null,
                'client_id' => $clientId,
                // The cashier, captured now. Deriving it later from the
                // terminal would name whoever signed in most recently.
                'user_id' => $userId,
                'currency_id' => $payload['currency_id'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total' => $total,
                'paid_amount' => $paid,
                'payment_type' => $payload['payment_type'] ?? null,
                'note' => $payload['note'] ?? null,
                'status' => Sale::STATUS_COMPLETED,
                'occurred_at' => $at,
            ]);

            // The client's balance is derived from their sales, but the
            // catalogue pull that carries it is keyed on clients.updated_at —
            // which a new sale does not move. Without this the debt badge is
            // correct on the till that rang the sale up and frozen on every
            // other one, for as long as nothing else edits the customer.
            $this->touchClient($clientId);

            foreach ($prepared as [$product, $quantity, $price, $lineTotal, $productUnit]) {
                // The conversion is snapshotted with the line. A shop that
                // redefines "karobka" from twelve to six must not thereby
                // change what last month's sales meant.
                $num = $productUnit?->base_units_numerator ?? 1;
                $den = $productUnit?->base_units_denominator ?? 1;
                $baseQuantity = round($quantity * $num / max(1, $den), 6);

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    // Copied, not referenced — see the migration.
                    'name' => $product->name,
                    'code' => $product->code,
                    'barcode' => $product->barcode,
                    'unit_id' => $productUnit?->unit_id ?? $product->unit_id,
                    'product_unit_id' => $productUnit?->id,
                    'unit_name' => $productUnit?->label() ?: $product->unit?->label(),
                    'conversion_numerator' => $num,
                    'conversion_denominator' => $den,
                    'quantity' => $quantity,
                    'base_quantity' => $baseQuantity,
                    'price' => $price,
                    'currency_id' => $payload['currency_id'] ?? $product->currency_id,
                    'total' => $lineTotal,
                ]);

                // Stock can go negative and that is deliberate. The shop sold
                // what it sold; a negative balance is a visible problem
                // somebody can fix, whereas a refused sale is a customer
                // standing at the counter with cash nobody will take.
                // In BASE units: selling one box of twelve takes twelve off
                // the shelf, not one.
                $this->stock->recordSale($product, $baseQuantity, $sale->id, $at, $userId);
            }

            return $sale->load('items');
        });
    }

    /**
     * Cancel a sale and put the stock back.
     *
     * The row stays. A customer holding the receipt must still be able to have
     * it looked up, and "this was cancelled at 14:20 by Anvar" is an answer —
     * a missing row is not.
     *
     * @throws BusinessException
     */
    /**
     * Move a client's updated_at so the pull re-sends them.
     *
     * Their balance lives in two other tables; this row is only the cursor
     * the sync reads. Cheap, and the alternative is a stale number that the
     * shop trusts.
     */
    private function touchClient(?int $clientId): void {
        if ($clientId === null) {
            return;
        }

        Client::query()->whereKey($clientId)->update(['updated_at' => now()]);
    }

    public function cancel(PosTerminal $terminal, int $saleId, ?Carbon $occurredAt): Sale {
        return DB::transaction(function () use ($terminal, $saleId, $occurredAt) {
            $sale = Sale::query()
                ->where('shop_id', $terminal->shop_id)
                ->whereKey($saleId)
                ->lockForUpdate()
                ->first();

            if ($sale === null) {
                throw new BusinessException('Sotuv topilmadi');
            }

            /*
             * A basket that was split across currencies is cancelled whole.
             *
             * The customer walked in once and is walking out with nothing;
             * undoing the so'm half and leaving the dollar half standing
             * would be a sale nobody made, and the cashier pressed cancel on
             * what they see as ONE row in Tarix. The group is the basket, so
             * the group is what comes back.
             */
            $basket = $sale->sale_group_id === null
                ? collect([$sale])
                : Sale::query()
                    ->where('shop_id', $terminal->shop_id)
                    ->where('sale_group_id', $sale->sale_group_id)
                    ->lockForUpdate()
                    ->get();

            foreach ($basket as $part) {
                if ($part->isCancelled()) {
                    // Not an error: a till retrying a cancel it already made
                    // must get the same answer, not a refusal.
                    continue;
                }

                $this->stock->reverseFor('sale', $part->id);

                $part->update([
                    'status' => Sale::STATUS_CANCELLED,
                    'cancelled_at' => $occurredAt ?? now(),
                ]);

                // The debt went back with the goods, so the badge has to
                // move too.
                $this->touchClient($part->client_id);
            }

            return $sale->refresh()->load('items');
        });
    }
}
