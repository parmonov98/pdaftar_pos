<?php

declare(strict_types=1);

namespace Pos\Services;

use Pos\Exceptions\BusinessException;
use Pos\Models\Product;
use Pos\Models\ProductPrice;
use Pos\Models\ProductUnit;

/**
 * Which unit, and what it costs.
 *
 * A product does not have "a price". It has a price per unit, per currency,
 * and per kind of sale — and none of those is derivable from the others: a
 * box is cheaper per bottle than a bottle, and credit costs more than cash.
 * Guessing any of them is how a shop loses money one line at a time.
 */
class PosPricingService {
    /**
     * The base unit — the one stock is counted in.
     *
     * Every product has exactly one. Products created before multi-unit
     * existed have none stored, so the product's own `unit_id` stands in and
     * the ratio is 1:1, which is what it always implicitly was.
     */
    public function baseUnit(Product $product): ?ProductUnit {
        return $product->productUnits()->where('is_base', true)->first()
            // A 1:1 row is a base unit whether or not the flag was ever set —
            // imports and rows written before the flag existed may not have it.
            //
            // Deliberately NOT "whichever unit exists first". A product given
            // a karobka before its dona would have had the box treated as the
            // base: prices read from products.price, and every sale taking one
            // off the shelf instead of twelve. Nothing would have raised.
            ?? $product->productUnits()
                ->where('base_units_numerator', 1)
                ->where('base_units_denominator', 1)
                ->orderBy('id')
                ->first();
    }

    /**
     * Resolve the unit a line is being sold in.
     *
     * @throws BusinessException
     */
    public function resolveUnit(Product $product, ?int $productUnitId): ?ProductUnit {
        if ($productUnitId === null) {
            return $this->baseUnit($product);
        }

        $unit = $product->productUnits()->whereKey($productUnitId)->first();

        if ($unit === null) {
            throw new BusinessException($product->name.': bu birlik mahsulotga tegishli emas');
        }

        if (! $unit->is_active) {
            throw new BusinessException($product->name.': bu birlik o\'chirilgan');
        }

        return $unit;
    }

    /**
     * What one of `$unit` costs.
     *
     * The order matters and is deliberate:
     *   1. the exact row for this unit, currency and kind
     *   2. the cash price for the same unit and currency — a shop that has
     *      not set a separate credit price charges the same, which is the
     *      common case and a safer default than refusing the sale
     *   3. `products.price`, but ONLY for the base unit in the shop's own
     *      currency, because that column means exactly that and nothing else
     *
     * Returns null when nothing applies. The caller decides whether that is
     * an error — at the till it is, because a cashier cannot invent a price,
     * but a catalogue screen can simply show a blank.
     */
    public function priceFor(
        Product $product,
        ProductUnit $unit,
        int $currencyId,
        string $type = ProductPrice::TYPE_SALE,
    ): ?float {
        $exact = ProductPrice::query()
            ->where('product_unit_id', $unit->id)
            ->where('currency_id', $currencyId)
            ->where('price_type', $type)
            ->value('amount');

        if ($exact !== null) {
            return (float) $exact;
        }

        if ($type !== ProductPrice::TYPE_SALE) {
            $cash = ProductPrice::query()
                ->where('product_unit_id', $unit->id)
                ->where('currency_id', $currencyId)
                ->where('price_type', ProductPrice::TYPE_SALE)
                ->value('amount');

            if ($cash !== null) {
                return (float) $cash;
            }
        }

        // The legacy column, and only where it actually means something. A
        // box priced from the bottle's column would be off by a factor of
        // twelve, so this deliberately does NOT scale by the conversion.
        $isBase = (int) $unit->base_units_numerator === 1 && (int) $unit->base_units_denominator === 1;

        // A null currency on the product means the shop's own, not "any".
        //
        // Rows written before the till sent a currency hold a price with
        // nothing beside it, and that number was typed in the only currency
        // the shop had. Treating it as a mismatch leaves the product
        // unpriceable while the till, which has no such check, shows the
        // price and puts it in the basket — the two disagree about the same
        // product and the sale is refused for a reason nobody can see.
        //
        // Resolved against the shop rather than accepted for anything, so a
        // som price is never handed back as dollars. A shop with no currency
        // of its own leaves such a product unpriceable, which is correct:
        // there is nothing on record saying what the number means, and
        // guessing is how a som is sold for a dollar.
        $sameCurrency = $product->currency_id === null
            ? $product->shop?->currency_id === $currencyId
            : $product->currency_id === $currencyId;

        if ($isBase && $sameCurrency) {
            return $product->price === null ? null : (float) $product->price;
        }

        return null;
    }

    /**
     * Give a product its base unit, so multi-unit code has something to stand
     * on. Idempotent.
     */
    public function ensureBaseUnit(Product $product): ?ProductUnit {
        if ($product->unit_id === null) {
            return null;
        }

        $existing = $this->baseUnit($product);

        if ($existing !== null) {
            return $existing;
        }

        return ProductUnit::create([
            'shop_id' => $product->shop_id,
            'product_id' => $product->id,
            'unit_id' => $product->unit_id,
            'base_units_numerator' => 1,
            'base_units_denominator' => 1,
            'is_base' => true,
            'is_active' => true,
        ]);
    }
}
