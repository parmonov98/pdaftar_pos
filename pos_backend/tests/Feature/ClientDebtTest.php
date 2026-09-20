<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Pos\Constants\PosScope;
use Pos\Exceptions\BusinessException;
use Pos\Models\Client;
use Pos\Models\ClientPayment;
use Pos\Models\Currency;
use Pos\Models\PosTerminal;
use Pos\Models\Product;
use Pos\Models\Sale;
use Pos\Models\Shop;
use Pos\Models\Unit;
use Pos\Models\User;
use Pos\Models\UserShop;
use Pos\Services\PosOperationDispatcher;
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

    private Currency $uzs;

    private Currency $usd;

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

        $this->uzs = Currency::create(['code' => 'UZS', 'name' => "So'm", 'sign' => "so'm"]);
        $this->usd = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'sign' => '$']);
        $this->shop->forceFill(['currency_id' => $this->uzs->id])->save();

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

    private function sell(float $qty, float $price, float $paid, ?int $clientId, ?int $currencyId = null): Sale {
        return app(PosSaleService::class)->create(
            $this->terminal(),
            [
                'items' => [['product_id' => $this->cola->id, 'quantity' => $qty, 'price' => $price]],
                'paid_amount' => $paid,
                'client_id' => $clientId,
                'currency_id' => $currencyId ?? $this->uzs->id,
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
        $this->assertSame(24000.0, $this->client->balanceIn($this->uzs->id));
    }

    public function test_a_part_payment_at_the_till_leaves_the_rest_owing(): void {
        $this->sell(2, 12000, 10000, $this->client->id);

        $this->assertSame(14000.0, $this->client->balanceIn($this->uzs->id));
    }

    public function test_paying_the_debt_back_clears_the_balance(): void {
        $this->sell(2, 12000, 0, $this->client->id);

        ClientPayment::create([
            'shop_id' => $this->shop->id,
            'client_id' => $this->client->id,
            'amount' => 24000,
            'currency_id' => $this->uzs->id,
            'payment_type' => 'cash',
            'user_id' => $this->user->id,
            'occurred_at' => now(),
        ]);

        $this->assertSame(0.0, $this->client->balanceIn($this->uzs->id));
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
            'currency_id' => $this->uzs->id,
            'user_id' => $this->user->id,
            'occurred_at' => now(),
        ]);

        $this->assertSame(-8000.0, $this->client->balanceIn($this->uzs->id));
    }

    /** The goods came back, so the debt did too. */
    public function test_cancelling_a_credit_sale_removes_the_debt(): void {
        $sale = $this->sell(2, 12000, 0, $this->client->id);
        $this->assertSame(24000.0, $this->client->balanceIn($this->uzs->id));

        app(PosSaleService::class)->cancel($this->terminal(), $sale->id, null);

        $this->assertSame(0.0, $this->client->balanceIn($this->uzs->id));
    }

    public function test_debts_from_several_sales_add_up(): void {
        $this->sell(1, 12000, 0, $this->client->id);
        $this->sell(2, 12000, 4000, $this->client->id);

        $this->assertSame(32000.0, $this->client->balanceIn($this->uzs->id));
    }

    /** One shop's debtors are not another's. */
    public function test_a_client_belongs_to_one_shop(): void {
        $other = Shop::create(['name' => 'Boshqa', 'owner_id' => $this->user->id]);
        $theirs = Client::create(['shop_id' => $other->id, 'name' => 'Ularniki']);

        $this->expectException(BusinessException::class);
        $this->sell(1, 12000, 0, $theirs->id);
    }

    /**
     * One phone number, one customer.
     *
     * A shop that has the same person twice has their debt split across two
     * rows: the cashier settles one, the other keeps owing, and nobody sees
     * it until somebody adds the two up by hand.
     */
    public function test_a_second_client_cannot_take_an_existing_phone_number(): void {
        $this->expectException(BusinessException::class);

        app(PosOperationDispatcher::class)->dispatch(
            $this->terminal(),
            'client.create',
            ['name' => 'Sobir', 'phone_number' => '+998901112233'],
            null,
            $this->user->id,
        );
    }

    /** Regulars with no number on file are told apart by name, not refused. */
    public function test_several_clients_may_have_no_phone_number(): void {
        $dispatcher = app(PosOperationDispatcher::class);

        foreach (['Anvar', 'Bobur'] as $name) {
            $dispatcher->dispatch($this->terminal(), 'client.create', ['name' => $name], null, $this->user->id);
        }

        $this->assertSame(2, Client::query()->whereNull('phone_number')->count());
    }

    /** An empty string is not a phone number, and must not collide with one. */
    public function test_a_blank_phone_number_is_stored_as_none(): void {
        $dispatcher = app(PosOperationDispatcher::class);

        $dispatcher->dispatch($this->terminal(), 'client.create', ['name' => 'Anvar', 'phone_number' => '  '], null, $this->user->id);
        $dispatcher->dispatch($this->terminal(), 'client.create', ['name' => 'Bobur', 'phone_number' => ''], null, $this->user->id);

        $this->assertSame(2, Client::query()->whereNull('phone_number')->count());
    }

    /**
     * Two currencies are two debts, not one number.
     *
     * Summed together, eleven dollars and twelve thousand som read as
     * 12,011 — and a dollar handed back cancels a som, so the shop is told
     * it has been paid when it has not. This is why the balance is a map.
     */
    public function test_debts_in_two_currencies_do_not_add_up(): void {
        $this->sell(1, 12000, 0, $this->client->id, $this->uzs->id);
        $this->sell(1, 11, 0, $this->client->id, $this->usd->id);

        $this->assertSame(12000.0, $this->client->balanceIn($this->uzs->id));
        $this->assertSame(11.0, $this->client->balanceIn($this->usd->id));
    }

    /** And a dollar paid back settles dollars, not som. */
    public function test_a_payment_only_settles_its_own_currency(): void {
        $this->sell(1, 12000, 0, $this->client->id, $this->uzs->id);
        $this->sell(1, 11, 0, $this->client->id, $this->usd->id);

        ClientPayment::create([
            'shop_id' => $this->shop->id,
            'client_id' => $this->client->id,
            'amount' => 11,
            'currency_id' => $this->usd->id,
            'user_id' => $this->user->id,
            'occurred_at' => Carbon::now(),
        ]);

        $this->assertSame(0.0, $this->client->balanceIn($this->usd->id));
        $this->assertSame(12000.0, $this->client->balanceIn($this->uzs->id));
    }

    /** A currency they have never traded in is not a debt. */
    public function test_an_untouched_currency_has_no_balance(): void {
        $this->sell(1, 12000, 0, $this->client->id, $this->uzs->id);

        $this->assertSame(0.0, $this->client->balanceIn($this->usd->id));
        $this->assertSame([$this->uzs->id => 12000.0], $this->client->balances());
    }

    /**
     * A debt that moved has to reach the other till.
     *
     * The balance is derived from sales and payments, but the catalogue pull
     * that carries it is keyed on clients.updated_at — and neither of those
     * writes touches that row. Without this the second till shows yesterday's
     * debt forever, and the shop trusts it.
     */
    public function test_a_credit_sale_makes_the_client_resync(): void {
        $before = $this->client->fresh()->updated_at;

        $this->travel(2)->seconds();
        $this->sell(1, 12000, 0, $this->client->id);

        $this->assertTrue($this->client->fresh()->updated_at->greaterThan($before));
    }

    /** And so does the repayment that clears it. */
    public function test_a_repayment_makes_the_client_resync(): void {
        $this->sell(1, 12000, 0, $this->client->id);
        $before = $this->client->fresh()->updated_at;

        $this->travel(2)->seconds();

        app(PosOperationDispatcher::class)->dispatch(
            $this->terminal(),
            'client.payment',
            ['client_id' => $this->client->id, 'amount' => 12000, 'currency_id' => $this->uzs->id],
            null,
            $this->user->id,
        );

        $this->assertTrue($this->client->fresh()->updated_at->greaterThan($before));
        $this->assertSame(0.0, $this->client->balanceIn($this->uzs->id));
    }

    /** Cancelling gives the goods back, and the badge has to follow. */
    public function test_cancelling_a_credit_sale_makes_the_client_resync(): void {
        $sale = $this->sell(1, 12000, 0, $this->client->id);
        $before = $this->client->fresh()->updated_at;

        $this->travel(2)->seconds();
        app(PosSaleService::class)->cancel($this->terminal(), $sale->id, null);

        $this->assertTrue($this->client->fresh()->updated_at->greaterThan($before));
    }

    /** A payment carries when it happened, like every other write. */
    public function test_a_payment_keeps_the_time_it_was_taken(): void {
        $this->sell(1, 12000, 0, $this->client->id);

        $payment = ClientPayment::create([
            'shop_id' => $this->shop->id,
            'client_id' => $this->client->id,
            'amount' => 12000,
            'currency_id' => $this->uzs->id,
            'user_id' => $this->user->id,
            'occurred_at' => Carbon::parse('2026-09-19 10:00'),
        ]);

        $this->assertSame('2026-09-19 10:00:00', $payment->occurred_at->format('Y-m-d H:i:s'));
    }

    /**
     * A credit sale that was part-paid, then cancelled.
     *
     * The debt goes back with the goods — but the money the customer already
     * handed over does NOT vanish with it. It stays a payment, and the
     * balance turns negative: the shop owes it back. Netting it to zero
     * instead would quietly keep cash the customer is standing there waiting
     * for.
     */
    public function test_cancelling_a_part_paid_credit_sale_leaves_the_shop_owing_the_money_back(): void {
        $sale = $this->sell(2, 12000, 0, $this->client->id);

        ClientPayment::create([
            'shop_id' => $this->shop->id,
            'client_id' => $this->client->id,
            'sale_id' => $sale->id,
            'amount' => 10000,
            'currency_id' => $this->uzs->id,
            'user_id' => $this->user->id,
            'occurred_at' => now(),
        ]);

        $this->assertSame(14000.0, $this->client->balanceIn($this->uzs->id));

        app(PosSaleService::class)->cancel($this->terminal(), $sale->id, null);

        // Not 0: they paid 10 000 for goods that went back on the shelf.
        $this->assertSame(-10000.0, $this->client->balanceIn($this->uzs->id));
        $this->assertSame(1, ClientPayment::where('sale_id', $sale->id)->count());
    }

    /**
     * And the till is told the figure BEFORE it cancels, so the confirmation
     * can name the money that is about to become credit rather than letting
     * the cashier discover it on the customer's balance afterwards.
     */
    public function test_the_history_row_carries_what_was_repaid_against_the_sale(): void {
        $sale = $this->sell(2, 12000, 0, $this->client->id);

        ClientPayment::create([
            'shop_id' => $this->shop->id,
            'client_id' => $this->client->id,
            'sale_id' => $sale->id,
            'amount' => 10000,
            'currency_id' => $this->uzs->id,
            'user_id' => $this->user->id,
            'occurred_at' => now(),
        ]);

        $terminal = $this->terminal();
        $token = $this->user->createToken('t', [PosScope::CATALOG_READ->value]);
        $terminal->update(['access_token_id' => $token->accessToken->getKey()]);

        $row = $this->withToken($token->plainTextToken)
            ->getJson('/api/pos/v1/sales/recent')
            ->json('data.0');

        $this->assertSame(10000.0, (float) $row['repaid_amount']);
        $this->assertSame('debt', $row['kind']);
        $this->assertSame('Sobir aka', $row['client_name']);
    }

    /**
     * Money taken AT THE COUNTER on a credit sale is part of the sale row, not
     * a separate payment — so cancelling takes it back out with everything
     * else and the client is square again.
     */
    public function test_cancelling_a_sale_part_paid_at_the_till_clears_the_whole_row(): void {
        $sale = $this->sell(2, 12000, 10000, $this->client->id);
        $this->assertSame(14000.0, $this->client->balanceIn($this->uzs->id));

        app(PosSaleService::class)->cancel($this->terminal(), $sale->id, null);

        $this->assertSame(0.0, $this->client->balanceIn($this->uzs->id));
    }

    /** One cancelled sale must not take another sale's debt with it. */
    public function test_cancelling_one_credit_sale_leaves_the_others_owing(): void {
        $first = $this->sell(1, 12000, 0, $this->client->id);
        $this->sell(2, 12000, 0, $this->client->id);

        $this->assertSame(36000.0, $this->client->balanceIn($this->uzs->id));

        app(PosSaleService::class)->cancel($this->terminal(), $first->id, null);

        $this->assertSame(24000.0, $this->client->balanceIn($this->uzs->id));
    }

    /** A cancelled sale in one currency leaves the other currency's debt alone. */
    public function test_cancelling_settles_only_its_own_currency(): void {
        $som = $this->sell(1, 12000, 0, $this->client->id, $this->uzs->id);
        $this->sell(1, 11, 0, $this->client->id, $this->usd->id);

        app(PosSaleService::class)->cancel($this->terminal(), $som->id, null);

        $this->assertSame(0.0, $this->client->balanceIn($this->uzs->id));
        $this->assertSame(11.0, $this->client->balanceIn($this->usd->id));
    }

    /**
     * Cancelling through the dispatcher — the path the till's outbox takes —
     * and not only through the service the other tests call directly.
     */
    public function test_the_outbox_path_cancels_a_credit_sale_and_moves_the_badge(): void {
        $sale = $this->sell(2, 12000, 0, $this->client->id);
        $before = $this->client->fresh()->updated_at;
        $this->travel(2)->seconds();

        app(PosOperationDispatcher::class)->dispatch(
            $this->terminal(),
            'sale.cancel',
            ['sale_id' => $sale->id],
            null,
            $this->user->id,
        );

        $this->assertSame(Sale::STATUS_CANCELLED, $sale->fresh()->status);
        $this->assertSame(0.0, $this->client->balanceIn($this->uzs->id));
        $this->assertTrue($this->client->fresh()->updated_at->greaterThan($before));
    }
}
