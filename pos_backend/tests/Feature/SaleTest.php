<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Pos\Constants\PosScope;
use Pos\Exceptions\BusinessException;
use Pos\Models\PosOperation;
use Pos\Models\PosTerminal;
use Pos\Models\Product;
use Pos\Models\Sale;
use Pos\Models\Shop;
use Pos\Models\StockMovement;
use Pos\Models\Unit;
use Pos\Models\User;
use Pos\Models\UserShop;
use Pos\Services\PosSaleService;
use Pos\Services\PosStockService;
use Tests\TestCase;

/**
 * Selling, and the ledger underneath it.
 *
 * The cases here are the ones that fail silently in a shop: a balance that
 * drifts from its ledger, a retry that rings a sale up twice, a stocktake that
 * erases another till's morning.
 */
class SaleTest extends TestCase {
    use RefreshDatabase;

    private Shop $shop;

    private User $user;

    private Product $cola;

    protected function setUp(): void {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Anvar',
            'phone_number' => '+998901234567',
            'password' => 'kassa12345',
        ]);

        $this->shop = Shop::create(['name' => 'Anvar Market', 'owner_id' => $this->user->id]);

        UserShop::create([
            'user_id' => $this->user->id,
            'shop_id' => $this->shop->id,
            'role' => UserShop::ROLE_OWNER,
        ]);

        $unit = Unit::create(['shop_id' => $this->shop->id, 'name' => 'dona', 'is_default' => true]);

        $this->cola = Product::create([
            'shop_id' => $this->shop->id,
            'name' => 'Cola 1.5L',
            'barcode' => '4780001',
            'price' => 12000,
            'unit_id' => $unit->id,
            'quantity' => 0,
        ]);

        // Through the ledger, like production does — a balance written
        // straight to the cache disappears the first time it is rebuilt.
        app(PosStockService::class)->recordOpening($this->cola, 10, Carbon::parse('2026-09-19 08:00'), $this->user->id);
        $this->cola->refresh();
    }

    /**
     * A cash sale, paid in full.
     *
     * paid_amount is computed rather than left out: a sale that is not fully
     * paid is a debt, and the service refuses one with nobody attached to it.
     * That is the behaviour under test elsewhere, not an inconvenience to
     * route around here.
     */
    private function sell(array $items, array $extra = [], ?Carbon $at = null): Sale {
        $terminal = $this->terminal();

        $subtotal = array_sum(array_map(fn ($i) => $i['quantity'] * $i['price'], $items));
        $due = max(0.0, $subtotal - (float) ($extra['discount_amount'] ?? 0));

        return app(PosSaleService::class)->create(
            $terminal,
            array_merge(['items' => $items, 'paid_amount' => $due, 'payment_type' => 'cash'], $extra),
            $at,
            $this->user->id,
        );
    }

    private function terminal(): PosTerminal {
        return PosTerminal::firstOrCreate(
            ['shop_id' => $this->shop->id, 'device_id' => 'test-device'],
            ['user_id' => $this->user->id, 'name' => 'Test kassa', 'provider' => 'pdaftar_pos', 'is_active' => true],
        );
    }

    public function test_a_sale_writes_its_lines_and_takes_the_stock(): void {
        $sale = $this->sell([['product_id' => $this->cola->id, 'quantity' => 2, 'price' => 12000]]);

        $this->assertSame('24000.000000', $sale->total);
        $this->assertCount(1, $sale->items);
        $this->assertSame(8.0, (float) $this->cola->fresh()->quantity);
    }

    /** The line keeps the name it was sold under, so an old receipt still reads. */
    public function test_a_sale_line_snapshots_the_product_name(): void {
        $sale = $this->sell([['product_id' => $this->cola->id, 'quantity' => 1, 'price' => 12000]]);

        $this->cola->update(['name' => 'Boshqa nom']);

        $this->assertSame('Cola 1.5L', $sale->items->first()->name);
    }

    public function test_the_discount_never_exceeds_the_subtotal(): void {
        $sale = $this->sell(
            [['product_id' => $this->cola->id, 'quantity' => 1, 'price' => 10000]],
            ['discount_amount' => 999999],
        );

        $this->assertSame(0.0, (float) $sale->total);
        $this->assertSame(10000.0, (float) $sale->discount_amount);
    }

    /**
     * The shop sells what it sells. A refused sale is a customer at the counter
     * with cash nobody will take; a negative balance is a visible problem
     * somebody can fix.
     */
    public function test_selling_more_than_the_stock_is_allowed_and_goes_negative(): void {
        $this->sell([['product_id' => $this->cola->id, 'quantity' => 15, 'price' => 12000]]);

        $this->assertSame(-5.0, (float) $this->cola->fresh()->quantity);
    }

    /** NULL stock means "never inventoried" — selling it must not invent a balance. */
    public function test_an_untracked_product_stays_untracked_after_a_sale(): void {
        $service = Product::create([
            'shop_id' => $this->shop->id,
            'name' => 'Yetkazib berish',
            'price' => 5000,
            'quantity' => null,
        ]);

        $this->sell([['product_id' => $service->id, 'quantity' => 1, 'price' => 5000]]);

        $this->assertNull($service->fresh()->quantity);
        $this->assertSame(0, StockMovement::where('product_id', $service->id)->count());
    }

    public function test_a_line_naming_another_shops_product_is_refused(): void {
        $other = Shop::create(['name' => 'Boshqa', 'owner_id' => $this->user->id]);
        $theirs = Product::create(['shop_id' => $other->id, 'name' => 'Ularniki', 'price' => 1, 'quantity' => 5]);

        $this->expectException(BusinessException::class);
        $this->sell([['product_id' => $theirs->id, 'quantity' => 1, 'price' => 1]]);
    }

    /** Cancelling returns the stock by removing the movements, not by offsetting them. */
    public function test_cancelling_a_sale_returns_the_stock_and_keeps_the_row(): void {
        $sale = $this->sell([['product_id' => $this->cola->id, 'quantity' => 3, 'price' => 12000]]);
        $this->assertSame(7.0, (float) $this->cola->fresh()->quantity);

        app(PosSaleService::class)->cancel($this->terminal(), $sale->id, null);

        $this->assertSame(10.0, (float) $this->cola->fresh()->quantity);
        $this->assertSame(Sale::STATUS_CANCELLED, $sale->fresh()->status);
        $this->assertSame(0, StockMovement::where('source_type', 'sale')->where('source_id', $sale->id)->count());
    }

    public function test_cancelling_twice_is_not_an_error(): void {
        $sale = $this->sell([['product_id' => $this->cola->id, 'quantity' => 1, 'price' => 12000]]);

        app(PosSaleService::class)->cancel($this->terminal(), $sale->id, null);
        app(PosSaleService::class)->cancel($this->terminal(), $sale->id, null);

        $this->assertSame(10.0, (float) $this->cola->fresh()->quantity);
    }

    /** The cache is only ever a projection of the ledger. */
    public function test_the_quantity_cache_equals_the_ledger(): void {
        $this->sell([['product_id' => $this->cola->id, 'quantity' => 2, 'price' => 12000]]);
        $this->sell([['product_id' => $this->cola->id, 'quantity' => 1, 'price' => 12000]]);

        $ledger = (float) StockMovement::where('product_id', $this->cola->id)->sum('quantity');

        $this->assertSame($ledger, (float) $this->cola->fresh()->quantity);
        $this->assertSame(7.0, (float) $this->cola->fresh()->quantity);
    }

    /**
     * Two tills offline all day. B sold later but synced first.
     *
     * Deltas commute, so the arrival order cannot change the balance — this is
     * the property the whole ledger design exists to get.
     */
    public function test_sales_arriving_out_of_order_land_on_the_same_balance(): void {
        $stock = app(PosStockService::class);

        // B's later sale arrives first, A's earlier sale second.
        $stock->record($this->cola, StockMovement::TYPE_SALE, -3, Carbon::parse('2026-09-19 14:00'), $this->user->id);
        $stock->record($this->cola, StockMovement::TYPE_SALE, -2, Carbon::parse('2026-09-19 10:00'), $this->user->id);

        $this->assertSame(5.0, (float) $this->cola->fresh()->quantity);
    }

    /**
     * The one that is NOT commutative, and the bug this design was built to
     * avoid: a count taken at 10:00 and synced at 18:00 must be measured
     * against the 10:00 balance, or it erases the sale B made at 14:00.
     */
    public function test_a_late_stocktake_is_measured_against_the_balance_at_its_own_time(): void {
        $stock = app(PosStockService::class);

        // B's sale at 14:00 arrives first: 10 - 3 = 7.
        $stock->record($this->cola, StockMovement::TYPE_SALE, -3, Carbon::parse('2026-09-19 14:00'), $this->user->id);
        $this->assertSame(7.0, (float) $this->cola->fresh()->quantity);

        // A counted 10 at 10:00 and only syncs now. Against the 10:00 balance
        // the count agrees with the ledger, so nothing should move.
        $stock->recordStocktake($this->cola, 10, Carbon::parse('2026-09-19 10:00'), $this->user->id);

        // Measured against "now" it would have written +3 and reported 10,
        // silently undoing B's sale.
        $this->assertSame(7.0, (float) $this->cola->fresh()->quantity);
    }

    public function test_a_stocktake_records_the_difference_it_actually_found(): void {
        $stock = app(PosStockService::class);

        $stock->recordStocktake($this->cola, 8, Carbon::parse('2026-09-19 12:00'), $this->user->id);

        $this->assertSame(8.0, (float) $this->cola->fresh()->quantity);
        $this->assertSame(
            -2.0,
            (float) StockMovement::where('type', StockMovement::TYPE_ADJUSTMENT)->value('quantity'),
        );
    }

    /** Attribution is captured at write time, not read off the terminal later. */
    public function test_a_sale_records_which_cashier_rang_it_up(): void {
        $sobir = User::create(['name' => 'Sobir', 'phone_number' => '+998907654321', 'password' => 'kassa12345']);
        UserShop::create(['user_id' => $sobir->id, 'shop_id' => $this->shop->id, 'role' => UserShop::ROLE_SELLER]);

        $anvarsSale = $this->sell([['product_id' => $this->cola->id, 'quantity' => 1, 'price' => 12000]]);

        $terminal = $this->terminal();
        $sobirsSale = app(PosSaleService::class)->create(
            $terminal,
            [
                'items' => [['product_id' => $this->cola->id, 'quantity' => 1, 'price' => 12000]],
                'paid_amount' => 12000,
            ],
            null,
            $sobir->id,
        );

        // The terminal changes hands; the sales must not.
        $terminal->update(['user_id' => $sobir->id]);

        $this->assertSame($this->user->id, $anvarsSale->fresh()->user_id);
        $this->assertSame($sobir->id, $sobirsSale->fresh()->user_id);
    }

    /**
     * A till's cached currency list goes stale. The answer must be something
     * it can act on, not "Server Error" from a foreign key — which reads as
     * the POS being broken rather than the till needing to sync.
     */
    public function test_a_stale_currency_id_is_a_validation_error_not_a_500(): void {
        $terminal = $this->terminal();
        $token = $this->user->createToken('t', [PosScope::SALES_WRITE->value]);
        $terminal->update(['access_token_id' => $token->accessToken->getKey()]);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/pos/v1/sales', [
                'client_operation_id' => (string) Str::uuid(),
                'currency_id' => 9999,
                'paid_amount' => 12000,
                'items' => [['product_id' => $this->cola->id, 'quantity' => 1, 'price' => 12000]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency_id');

        $this->assertSame(0, Sale::count());
    }

    public function test_an_empty_cart_is_refused(): void {
        $this->expectException(BusinessException::class);
        $this->sell([]);
    }

    /** A retry must not ring the same sale up twice. */
    public function test_the_same_operation_id_applies_once(): void {
        $terminal = $this->terminal();
        $token = $this->user->createToken('t', [PosScope::SALES_WRITE->value]);
        $terminal->update(['access_token_id' => $token->accessToken->getKey()]);

        $opId = (string) Str::uuid();
        $body = [
            'client_operation_id' => $opId,
            'paid_amount' => 24000,
            'items' => [['product_id' => $this->cola->id, 'quantity' => 2, 'price' => 12000]],
        ];

        $first = $this->withToken($token->plainTextToken)->postJson('/api/pos/v1/sales', $body);
        $this->app['auth']->forgetGuards();
        $second = $this->withToken($token->plainTextToken)->postJson('/api/pos/v1/sales', $body);

        $first->assertSuccessful();
        $second->assertSuccessful();
        $this->assertFalse($first->json('data.replayed'));
        $this->assertTrue($second->json('data.replayed'));

        // The till files its own rows under the server's id, so the response
        // has to carry it — on the replay too, or a retry leaves the till
        // unable to reconcile the sale it already made.
        $this->assertSame(Sale::first()->id, $first->json('data.data.sale.id'));
        $this->assertSame(Sale::first()->id, $second->json('data.data.sale.id'));

        // And pos_operations must name the row it produced, which is how a
        // receipt is traced back when someone asks about it weeks later.
        $this->assertSame(Sale::first()->id, PosOperation::first()->entity_id);
        $this->assertSame(1, Sale::count());
        $this->assertSame(8.0, (float) $this->cola->fresh()->quantity);
    }
}
