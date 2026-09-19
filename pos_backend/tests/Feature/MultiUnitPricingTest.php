<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Pos\Exceptions\BusinessException;
use Pos\Models\Currency;
use Pos\Models\PosTerminal;
use Pos\Models\Product;
use Pos\Models\ProductPrice;
use Pos\Models\ProductUnit;
use Pos\Models\Sale;
use Pos\Models\Shop;
use Pos\Models\Unit;
use Pos\Models\User;
use Pos\Models\UserShop;
use Pos\Services\PosPricingService;
use Pos\Services\PosSaleService;
use Pos\Services\PosStockService;
use Tests\TestCase;

/**
 * Selling the same product by the bottle and by the box, in two currencies.
 *
 * The failure this guards against is arithmetic that looks right: a box sold
 * for a bottle's price, or a box that takes one off the shelf instead of
 * twelve. Neither raises anything — the shop just loses money or loses count.
 */
class MultiUnitPricingTest extends TestCase {
    use RefreshDatabase;

    private Shop $shop;

    private User $user;

    private Product $cola;

    private Unit $dona;

    private Unit $karobka;

    private Currency $uzs;

    private Currency $usd;

    protected function setUp(): void {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Anvar', 'phone_number' => '+998901234567', 'password' => 'kassa12345',
        ]);
        $this->shop = Shop::create(['name' => 'Anvar Market', 'owner_id' => $this->user->id]);
        UserShop::create(['user_id' => $this->user->id, 'shop_id' => $this->shop->id, 'role' => UserShop::ROLE_OWNER]);

        $this->dona = Unit::create(['shop_id' => $this->shop->id, 'name' => 'dona', 'is_default' => true]);
        $this->karobka = Unit::create(['shop_id' => $this->shop->id, 'name' => 'karobka']);

        $this->uzs = Currency::create(['code' => 'UZS', 'name' => "So'm", 'sign' => "so'm"]);
        $this->usd = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'sign' => '$']);

        $this->cola = Product::create([
            'shop_id' => $this->shop->id,
            'name' => 'Cola 1.5L',
            'price' => 12000,
            'unit_id' => $this->dona->id,
            'currency_id' => $this->uzs->id,
            'quantity' => 0,
        ]);
        app(PosStockService::class)->recordOpening($this->cola, 100, null, $this->user->id);
        app(PosPricingService::class)->ensureBaseUnit($this->cola);
    }

    private function boxUnit(int $perBox = 12): ProductUnit {
        return ProductUnit::create([
            'shop_id' => $this->shop->id,
            'product_id' => $this->cola->id,
            'unit_id' => $this->karobka->id,
            'base_units_numerator' => $perBox,
            'base_units_denominator' => 1,
        ]);
    }

    private function terminal(): PosTerminal {
        return PosTerminal::firstOrCreate(
            ['shop_id' => $this->shop->id, 'device_id' => 'test-device'],
            ['user_id' => $this->user->id, 'name' => 'Kassa', 'provider' => 'pdaftar_pos', 'is_active' => true],
        );
    }

    private function sell(array $line, ?int $currencyId = null): Sale {
        $price = $line['price'] ?? 0;
        $qty = $line['quantity'];

        return app(PosSaleService::class)->create(
            $this->terminal(),
            [
                'items' => [$line],
                'currency_id' => $currencyId ?? $this->uzs->id,
                'paid_amount' => $qty * $price,
            ],
            null,
            $this->user->id,
        );
    }

    public function test_every_product_gets_a_base_unit(): void {
        $base = app(PosPricingService::class)->baseUnit($this->cola);

        $this->assertNotNull($base);
        $this->assertSame($this->dona->id, $base->unit_id);
        $this->assertSame(1, $base->base_units_numerator);
    }

    /**
     * A product given its box before its bottle.
     *
     * The old fallback took "whichever unit exists first" as the base, so the
     * box became the base: every price read from products.price, and every
     * sale took one off the shelf instead of twelve. Nothing raised.
     */
    public function test_a_non_unit_ratio_is_never_mistaken_for_the_base(): void {
        $pricing = app(PosPricingService::class);

        $fresh = Product::create([
            'shop_id' => $this->shop->id, 'name' => 'Fanta', 'price' => 11000,
            'unit_id' => $this->dona->id, 'currency_id' => $this->uzs->id, 'quantity' => 0,
        ]);

        // The box arrives first, with no base unit anywhere.
        ProductUnit::create([
            'shop_id' => $this->shop->id, 'product_id' => $fresh->id,
            'unit_id' => $this->karobka->id,
            'base_units_numerator' => 12, 'base_units_denominator' => 1,
        ]);

        $this->assertNull($pricing->baseUnit($fresh), 'a 12:1 box is not a base unit');

        // And asking for one creates the real thing rather than adopting it.
        $base = $pricing->ensureBaseUnit($fresh);
        $this->assertSame($this->dona->id, $base->unit_id);
        $this->assertSame(1, $base->base_units_numerator);
    }

    /** The one that costs money when it is wrong. */
    public function test_selling_one_box_takes_twelve_off_the_shelf(): void {
        $box = $this->boxUnit(12);

        $this->sell([
            'product_id' => $this->cola->id,
            'product_unit_id' => $box->id,
            'quantity' => 1,
            'price' => 130000,
        ]);

        $this->assertSame(88.0, (float) $this->cola->fresh()->quantity);
    }

    public function test_the_line_records_the_unit_and_the_base_quantity(): void {
        $box = $this->boxUnit(12);

        $sale = $this->sell([
            'product_id' => $this->cola->id,
            'product_unit_id' => $box->id,
            'quantity' => 2,
            'price' => 130000,
        ]);

        $item = $sale->items->first();
        $this->assertSame('karobka', $item->unit_name);
        $this->assertSame(2.0, (float) $item->quantity);
        $this->assertSame(24.0, (float) $item->base_quantity);
        $this->assertSame(12, (int) $item->conversion_numerator);
    }

    /**
     * A supplier changes the packaging and the shop redefines the box. Last
     * month's sales must still mean what they meant.
     */
    public function test_redefining_a_box_does_not_rewrite_old_sales(): void {
        $box = $this->boxUnit(12);

        $sale = $this->sell([
            'product_id' => $this->cola->id,
            'product_unit_id' => $box->id,
            'quantity' => 1,
            'price' => 130000,
        ]);

        $box->update(['base_units_numerator' => 6]);

        $item = $sale->fresh()->items->first();
        $this->assertSame(12, (int) $item->conversion_numerator);
        $this->assertSame(12.0, (float) $item->base_quantity);
    }

    /** Fractions stay exact: three thirds are a whole, 0.333333 × 3 is not. */
    public function test_a_fractional_unit_adds_back_up(): void {
        $third = ProductUnit::create([
            'shop_id' => $this->shop->id,
            'product_id' => $this->cola->id,
            'unit_id' => $this->karobka->id,
            'base_units_numerator' => 1,
            'base_units_denominator' => 3,
        ]);

        $this->sell([
            'product_id' => $this->cola->id,
            'product_unit_id' => $third->id,
            'quantity' => 3,
            'price' => 5000,
        ]);

        $this->assertSame(99.0, (float) $this->cola->fresh()->quantity);
    }

    public function test_a_price_is_per_unit_and_per_currency(): void {
        $box = $this->boxUnit(12);
        $pricing = app(PosPricingService::class);

        ProductPrice::create([
            'shop_id' => $this->shop->id, 'product_id' => $this->cola->id,
            'product_unit_id' => $box->id, 'currency_id' => $this->uzs->id,
            'price_type' => ProductPrice::TYPE_SALE, 'amount' => 130000,
        ]);
        ProductPrice::create([
            'shop_id' => $this->shop->id, 'product_id' => $this->cola->id,
            'product_unit_id' => $box->id, 'currency_id' => $this->usd->id,
            'price_type' => ProductPrice::TYPE_SALE, 'amount' => 11,
        ]);

        $this->assertSame(130000.0, $pricing->priceFor($this->cola, $box, $this->uzs->id));
        $this->assertSame(11.0, $pricing->priceFor($this->cola, $box, $this->usd->id));
    }

    /** A shop that has not set a credit price charges the cash one. */
    public function test_credit_falls_back_to_the_cash_price(): void {
        $box = $this->boxUnit(12);
        $pricing = app(PosPricingService::class);

        ProductPrice::create([
            'shop_id' => $this->shop->id, 'product_id' => $this->cola->id,
            'product_unit_id' => $box->id, 'currency_id' => $this->uzs->id,
            'price_type' => ProductPrice::TYPE_SALE, 'amount' => 130000,
        ]);

        $this->assertSame(
            130000.0,
            $pricing->priceFor($this->cola, $box, $this->uzs->id, ProductPrice::TYPE_CREDIT),
        );
    }

    /** And uses it when one IS set. */
    public function test_a_credit_price_wins_where_it_exists(): void {
        $box = $this->boxUnit(12);
        $pricing = app(PosPricingService::class);

        foreach ([[ProductPrice::TYPE_SALE, 130000], [ProductPrice::TYPE_CREDIT, 140000]] as [$type, $amount]) {
            ProductPrice::create([
                'shop_id' => $this->shop->id, 'product_id' => $this->cola->id,
                'product_unit_id' => $box->id, 'currency_id' => $this->uzs->id,
                'price_type' => $type, 'amount' => $amount,
            ]);
        }

        $this->assertSame(
            140000.0,
            $pricing->priceFor($this->cola, $box, $this->uzs->id, ProductPrice::TYPE_CREDIT),
        );
    }

    /**
     * products.price is the BASE unit's price in the shop's own currency. A
     * box priced from it would be out by a factor of twelve, so it must not
     * be used as a fallback for one.
     */
    public function test_the_legacy_price_column_never_prices_a_box(): void {
        $box = $this->boxUnit(12);
        $pricing = app(PosPricingService::class);

        $this->assertNull($pricing->priceFor($this->cola, $box, $this->uzs->id));

        $base = $pricing->baseUnit($this->cola);
        $this->assertSame(12000.0, $pricing->priceFor($this->cola, $base, $this->uzs->id));
    }

    /** Nor in a currency it was never quoted in. */
    public function test_the_legacy_price_column_never_crosses_currency(): void {
        $pricing = app(PosPricingService::class);
        $base = $pricing->baseUnit($this->cola);

        $this->assertNull($pricing->priceFor($this->cola, $base, $this->usd->id));
    }

    /** A cashier cannot invent a price, so an unpriced line is refused. */
    public function test_a_line_with_no_resolvable_price_is_refused(): void {
        $box = $this->boxUnit(12);

        $this->expectException(BusinessException::class);
        app(PosSaleService::class)->create(
            $this->terminal(),
            [
                'items' => [['product_id' => $this->cola->id, 'product_unit_id' => $box->id, 'quantity' => 1]],
                'currency_id' => $this->uzs->id,
                'paid_amount' => 0,
                'client_id' => null,
            ],
            null,
            $this->user->id,
        );
    }

    public function test_a_unit_from_another_product_is_refused(): void {
        $other = Product::create([
            'shop_id' => $this->shop->id, 'name' => 'Non', 'price' => 4000,
            'unit_id' => $this->dona->id, 'currency_id' => $this->uzs->id, 'quantity' => 0,
        ]);
        $theirs = ProductUnit::create([
            'shop_id' => $this->shop->id, 'product_id' => $other->id,
            'unit_id' => $this->karobka->id, 'base_units_numerator' => 5, 'base_units_denominator' => 1,
        ]);

        $this->expectException(BusinessException::class);
        $this->sell([
            'product_id' => $this->cola->id,
            'product_unit_id' => $theirs->id,
            'quantity' => 1,
            'price' => 1000,
        ]);
    }

    public function test_a_disabled_unit_cannot_be_sold(): void {
        $box = $this->boxUnit(12);
        $box->update(['is_active' => false]);

        $this->expectException(BusinessException::class);
        $this->sell([
            'product_id' => $this->cola->id,
            'product_unit_id' => $box->id,
            'quantity' => 1,
            'price' => 130000,
        ]);
    }
}
