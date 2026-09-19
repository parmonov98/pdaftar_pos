<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Pos\Models\Shop;
use Pos\Models\User;
use Pos\Models\UserShop;
use Tests\TestCase;

/**
 * Signing in to the POS on its own.
 *
 * The thing worth protecting here is that none of it touches pDaftar. A test
 * that passes only because a sibling checkout happens to be present would be
 * the exact regression this work removed.
 */
class AuthTest extends TestCase {
    use RefreshDatabase;

    private const VALID = [
        'name' => 'Anvar Karimov',
        'phone_number' => '+998901234567',
        'password' => 'kassa12345',
        'shop_name' => 'Anvar Market',
    ];

    public function test_registration_creates_user_shop_and_membership_together(): void {
        $response = $this->postJson('/api/pos/v1/auth/register', self::VALID);

        $response->assertCreated();
        $response->assertJsonPath('data.user.phone_number', '+998901234567');
        $response->assertJsonPath('data.user.linked_to_pdaftar', false);
        $response->assertJsonPath('data.shops.0.name', 'Anvar Market');
        $response->assertJsonPath('data.shops.0.role', 'owner');
        $this->assertNotEmpty($response->json('data.token'));

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('shops', 1);
        $this->assertDatabaseHas('user_shop', ['role' => UserShop::ROLE_OWNER]);
    }

    /**
     * terminal_limit comes from a database default the freshly created model
     * has never read. Reporting null told the till the shop may open no tills.
     */
    public function test_registration_reports_the_terminal_limit(): void {
        $response = $this->postJson('/api/pos/v1/auth/register', self::VALID);

        $this->assertNotNull($response->json('data.shops.0.terminal_limit'));
    }

    public function test_the_password_is_never_stored_in_the_clear(): void {
        $this->postJson('/api/pos/v1/auth/register', self::VALID)->assertCreated();

        $this->assertNotSame('kassa12345', User::first()->password);
    }

    /**
     * "+998 90 123 45 67" and "998901234567" are one person. Two accounts for
     * one cashier splits their sales across two names.
     */
    public function test_phone_numbers_are_normalised_on_both_sides(): void {
        $this->postJson('/api/pos/v1/auth/register', [
            ...self::VALID,
            'phone_number' => '+998 90 123 45 67',
        ])->assertCreated();

        $this->assertSame('+998901234567', User::first()->phone_number);

        $this->postJson('/api/pos/v1/auth/login', [
            'phone_number' => '998901234567',
            'password' => 'kassa12345',
        ])->assertOk();
    }

    public function test_a_second_registration_on_the_same_number_is_refused(): void {
        $this->postJson('/api/pos/v1/auth/register', self::VALID)->assertCreated();

        $this->postJson('/api/pos/v1/auth/register', self::VALID)
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_malformed_phone_number_is_refused(): void {
        $this->postJson('/api/pos/v1/auth/register', [...self::VALID, 'phone_number' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number');
    }

    public function test_login_returns_a_token_and_the_shops(): void {
        $this->postJson('/api/pos/v1/auth/register', self::VALID)->assertCreated();

        $response = $this->postJson('/api/pos/v1/auth/login', [
            'phone_number' => self::VALID['phone_number'],
            'password' => self::VALID['password'],
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertCount(1, $response->json('data.shops'));
    }

    /**
     * A wrong password and an unknown number must be indistinguishable —
     * otherwise the difference enumerates which numbers are registered.
     */
    public function test_wrong_password_and_unknown_number_are_indistinguishable(): void {
        $this->postJson('/api/pos/v1/auth/register', self::VALID)->assertCreated();

        $wrongPassword = $this->postJson('/api/pos/v1/auth/login', [
            'phone_number' => self::VALID['phone_number'],
            'password' => 'not-the-password',
        ]);

        $unknownNumber = $this->postJson('/api/pos/v1/auth/login', [
            'phone_number' => '+998900000000',
            'password' => 'not-the-password',
        ]);

        $wrongPassword->assertStatus(401);
        $unknownNumber->assertStatus(401);
        $this->assertSame($wrongPassword->json('message'), $unknownNumber->json('message'));
    }

    public function test_a_deactivated_account_cannot_sign_in(): void {
        $this->postJson('/api/pos/v1/auth/register', self::VALID)->assertCreated();
        User::first()->forceFill(['is_active' => false])->save();

        $this->postJson('/api/pos/v1/auth/login', [
            'phone_number' => self::VALID['phone_number'],
            'password' => self::VALID['password'],
        ])->assertStatus(403);
    }

    public function test_me_requires_a_token(): void {
        $this->getJson('/api/pos/v1/auth/me')->assertStatus(401);
    }

    public function test_me_answers_for_the_token_holder(): void {
        $token = $this->postJson('/api/pos/v1/auth/register', self::VALID)->json('data.token');

        $this->withToken($token)
            ->getJson('/api/pos/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Anvar Karimov')
            ->assertJsonPath('data.shops.0.name', 'Anvar Market');
    }

    /**
     * Signing out of one till must not sign the owner's phone out of another,
     * so only the token that made the request is dropped.
     */
    public function test_logout_revokes_only_the_calling_token(): void {
        $this->postJson('/api/pos/v1/auth/register', self::VALID)->assertCreated();
        $user = User::first();

        $keep = $user->createToken('other-till')->plainTextToken;
        $drop = $user->createToken('this-till')->plainTextToken;

        $this->withToken($drop)->postJson('/api/pos/v1/auth/logout')->assertOk();

        // Laravel keeps the resolved guard between requests inside one test,
        // so without this the next call is answered by the user the logout
        // request already authenticated — the deleted token is never consulted
        // and the assertion passes for the wrong reason. Verified against the
        // real server: a revoked token there does get 401.
        $this->app['auth']->forgetGuards();

        $this->withToken($drop)->getJson('/api/pos/v1/auth/me')->assertStatus(401);
        $this->withToken($keep)->getJson('/api/pos/v1/auth/me')->assertOk();
    }

    /** A seller belongs to a shop they did not create, and sees only that one. */
    public function test_a_seller_sees_only_the_shops_they_belong_to(): void {
        $this->postJson('/api/pos/v1/auth/register', self::VALID)->assertCreated();
        $ownersShop = Shop::first();

        $other = Shop::create(['name' => "Boshqa do'kon", 'owner_id' => 999]);
        $seller = User::create([
            'name' => 'Sobir',
            'phone_number' => '+998907654321',
            'password' => 'kassa12345',
        ]);
        UserShop::create([
            'user_id' => $seller->id,
            'shop_id' => $other->id,
            'role' => UserShop::ROLE_SELLER,
        ]);

        $token = $this->postJson('/api/pos/v1/auth/login', [
            'phone_number' => '+998907654321',
            'password' => 'kassa12345',
        ])->json('data.token');

        $response = $this->withToken($token)->getJson('/api/pos/v1/auth/me');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.shops'));
        $response->assertJsonPath('data.shops.0.id', $other->id);
        $response->assertJsonPath('data.shops.0.role', 'seller');
        $this->assertNotSame($ownersShop->id, $response->json('data.shops.0.id'));
    }
}
