<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Pos\Exceptions\BusinessException;
use Pos\Models\Client;
use Pos\Models\ClientPayment;
use Pos\Models\PosTerminal;
use Pos\Models\Product;
use Pos\Models\Sale;
use Pos\Models\Shop;
use Pos\Models\Unit;
use Pos\Models\User;
use Pos\Models\UserShop;
use Pos\Services\PosSaleService;
use Pos\Services\PosStockService;
use Tests\TestCase;

/**
 * Nasiya — selling on credit, and getting paid back.
 *
 * The balance is never stored. Everything here checks that it still adds up
 * from the rows, because the failure mode of a cached balance is asking a
 * customer for money they already handed over.
 */
class ClientDebtTest extends TestCase {
    use RefreshDatabase;

    private Shop $shop;

    private User $user;

    private Client $client;

    private Product $cola;

    protected function setUp(): void {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Anvar',
            'phone_number' => '+998901234567',
            'password' => 'kassa12345',
        ]);
        $this->shop = Shop::create(['name' => 'Anvar Market', 'owner_id' => $this->user->id]);
        UserShop::create(['user_id' => $this->user->id, 'shop_id' => $this->shop->id, 'role' => UserShop::ROLE_OWNER]);

        $unit = Unit::create(['shop_id' => $this->shop->id, 'name' => 'dona', 'is_default' => true]);

        $this->cola = Product::create([
            'shop_id' => $this->shop->id,
            'name' => 'Cola 1.5L',
            'price' => 12000,
            'unit_id' => $unit->id,
            'quantity' => 0,
        ]);
        app(PosStockService::class)->recordOpening($this->cola, 100, null, $this->user->id);

        $this->client = Client::create(['shop_id' => $this->shop->id, 'name' => 'Sobir aka', 'phone_number' => '+998901112233']);
    }

    private function terminal(): PosTerminal {
        return PosTerminal::firstOrCreate(
            ['shop_id' => $this->shop->id, 'device_id' => 'test-device'],
            ['user_id' => $this->user->id, 'name' => 'Test kassa', 'provider' => 'pdaftar_pos', 'is_active' => true],
        );
    }

    private function sell(float $qty, float $price, float $paid, ?int $clientId): Sale {
        return app(PosSaleService::class)->create(
            $this->terminal(),
            [
                'items' => [['product_id' => $this->cola->id, 'quantity' => $qty, 'price' => $price]],
                'paid_amount' => $paid,
                'client_id' => $clientId,
            ],
            null,
            $this->user->id,
        );
    }

    /**
     * The guard that matters: an unpaid sale with nobody attached is money
     * the shop has no way to chase.
     */
    public function test_an_unpaid_sale_without_a_client_is_refused(): void {
        $this->expectException(BusinessException::class);
        $this->sell(1, 12000, 0, null);
    }

    public function test_a_partly_paid_sale_without_a_client_is_refused(): void {
        $this->expectException(BusinessException::class);
        $this->sell(1, 12000, 5000, null);
    }

    public function test_a_fully_paid_sale_needs_no_client(): void {
        $sale = $this->sell(1, 12000, 12000, null);

        $this->assertNull($sale->client_id);
        $this->assertFalse($sale->isCredit());
    }

    public function test_selling_on_credit_records_the_debt(): void {
        $sale = $this->sell(2, 12000, 0, $this->client->id);

        $this->assertSame($this->client->id, $sale->client_id);
        $this->assertSame(24000.0, $sale->outstanding());
        $this->assertSame(24000.0, $this->client->balance());
    }

    public function test_a_part_payment_at_the_till_leaves_the_rest_owing(): void {
        $this->sell(2, 12000, 10000, $this->client->id);

        $this->assertSame(14000.0, $this->client->balance());
    }

    public function test_paying_the_debt_back_clears_the_balance(): void {
        $this->sell(2, 12000, 0, $this->client->id);

        ClientPayment::create([
            'shop_id' => $this->shop->id,
            'client_id' => $this->client->id,
            'amount' => 24000,
            'payment_type' => 'cash',
            'user_id' => $this->user->id,
            'occurred_at' => now(),
        ]);

        $this->assertSame(0.0, $this->client->balance());
    }

    /**
     * Overpaying is allowed and shows as credit. Refusing the note a customer
     * is holding out helps nobody, and a negative balance is visible.
     */
    public function test_overpaying_leaves_the_client_in_credit(): void {
        $this->sell(1, 12000, 0, $this->client->id);

        ClientPayment::create([
            'shop_id' => $this->shop->id,
            'client_id' => $this->client->id,
            'amount' => 20000,
            'user_id' => $this->user->id,
            'occurred_at' => now(),
        ]);

        $this->assertSame(-8000.0, $this->client->balance());
    }

    /** The goods came back, so the debt did too. */
    public function test_cancelling_a_credit_sale_removes_the_debt(): void {
        $sale = $this->sell(2, 12000, 0, $this->client->id);
        $this->assertSame(24000.0, $this->client->balance());

        app(PosSaleService::class)->cancel($this->terminal(), $sale->id, null);

        $this->assertSame(0.0, $this->client->balance());
    }

    public function test_debts_from_several_sales_add_up(): void {
        $this->sell(1, 12000, 0, $this->client->id);
        $this->sell(2, 12000, 4000, $this->client->id);

        $this->assertSame(32000.0, $this->client->balance());
    }

    /** One shop's debtors are not another's. */
    public function test_a_client_belongs_to_one_shop(): void {
        $other = Shop::create(['name' => 'Boshqa', 'owner_id' => $this->user->id]);
        $theirs = Client::create(['shop_id' => $other->id, 'name' => 'Ularniki']);

        $this->expectException(BusinessException::class);
        $this->sell(1, 12000, 0, $theirs->id);
    }

    /** A payment carries when it happened, like every other write. */
    public function test_a_payment_keeps_the_time_it_was_taken(): void {
        $this->sell(1, 12000, 0, $this->client->id);

        $payment = ClientPayment::create([
            'shop_id' => $this->shop->id,
            'client_id' => $this->client->id,
            'amount' => 12000,
            'user_id' => $this->user->id,
            'occurred_at' => Carbon::parse('2026-09-19 10:00'),
        ]);

        $this->assertSame('2026-09-19 10:00:00', $payment->occurred_at->format('Y-m-d H:i:s'));
    }
}
