<?php

declare(strict_types=1);

namespace Pos\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pos\Exceptions\BusinessException;
use Pos\Models\PosTerminal;
use Pos\Models\Product;
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
class PosSaleService
{
    public function __construct(private readonly PosStockService $stock) {}

    /**
     * @param  array{
     *     currency_id?: int|null, discount_amount?: float|null, payment_type?: string|null,
     *     paid_amount?: float|null, note?: string|null,
     *     items: array<int, array{product_id: int, quantity: float, price: float}>
     * }  $payload
     *
     * @throws BusinessException
     */
    public function create(PosTerminal $terminal, array $payload, ?Carbon $occurredAt, int $userId): Sale
    {
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
                $price = (float) ($line['price'] ?? 0);

                if ($quantity <= 0) {
                    throw new BusinessException($product->name.': miqdor noldan katta bo\'lishi kerak');
                }

                $total = round($quantity * $price, 6);
                $subtotal += $total;

                $prepared[] = [$product, $quantity, $price, $total];
            }

            $discount = round((float) ($payload['discount_amount'] ?? 0), 6);
            $discount = max(0.0, min($discount, $subtotal));
            $total = round($subtotal - $discount, 6);

            $sale = Sale::create([
                'shop_id' => $terminal->shop_id,
                'pos_terminal_id' => $terminal->id,
                // The cashier, captured now. Deriving it later from the
                // terminal would name whoever signed in most recently.
                'user_id' => $userId,
                'currency_id' => $payload['currency_id'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total' => $total,
                'paid_amount' => round((float) ($payload['paid_amount'] ?? 0), 6),
                'payment_type' => $payload['payment_type'] ?? null,
                'note' => $payload['note'] ?? null,
                'status' => Sale::STATUS_COMPLETED,
                'occurred_at' => $at,
            ]);

            foreach ($prepared as [$product, $quantity, $price, $lineTotal]) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    // Copied, not referenced — see the migration.
                    'name' => $product->name,
                    'code' => $product->code,
                    'barcode' => $product->barcode,
                    'unit_id' => $product->unit_id,
                    'unit_name' => $product->unit?->label(),
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $lineTotal,
                ]);

                // Stock can go negative and that is deliberate. The shop sold
                // what it sold; a negative balance is a visible problem
                // somebody can fix, whereas a refused sale is a customer
                // standing at the counter with cash nobody will take.
                $this->stock->recordSale($product, $quantity, $sale->id, $at, $userId);
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
    public function cancel(PosTerminal $terminal, int $saleId, ?Carbon $occurredAt): Sale
    {
        return DB::transaction(function () use ($terminal, $saleId, $occurredAt) {
            $sale = Sale::query()
                ->where('shop_id', $terminal->shop_id)
                ->whereKey($saleId)
                ->lockForUpdate()
                ->first();

            if ($sale === null) {
                throw new BusinessException('Sotuv topilmadi');
            }

            if ($sale->isCancelled()) {
                // Not an error: a till retrying a cancel it already made must
                // get the same answer, not a refusal.
                return $sale->load('items');
            }

            $this->stock->reverseFor('sale', $sale->id);

            $sale->update([
                'status' => Sale::STATUS_CANCELLED,
                'cancelled_at' => $occurredAt ?? now(),
            ]);

            return $sale->load('items');
        });
    }
}
