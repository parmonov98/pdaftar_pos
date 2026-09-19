<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Pos\Models\Shop;
use Pos\Models\User;
use Pos\Models\UserShop;

/**
 * Signing in to the POS itself.
 *
 * The till used to post its password to pDaftar's mobile API and come back
 * with a pDaftar token. That stopped being possible the moment the POS moved
 * to its own server and its own database: /api/mobile/login is not a route
 * this application has, and a pDaftar token names a row in a
 * personal_access_tokens table this application cannot see.
 *
 * So the POS issues its own credentials. Linking an account to pDaftar comes
 * later and is additive — see the nullable pdaftar_user_id column.
 *
 * Response shape is {data: {...}} because that is what the till already reads
 * and there is no reason to churn it.
 */
class AuthController extends Controller {
    /**
     * Register an owner and their first shop, in one step.
     *
     * One step on purpose: a POS account with no shop can do nothing at all —
     * it cannot open a till, which is the only thing the product does. Making
     * someone register and then separately create a shop is a dead end they
     * have to find their own way out of.
     */
    public function register(Request $request): JsonResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone_number' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:6', 'max:72'],
            'shop_name' => ['required', 'string', 'max:160'],
        ]);

        $phone = User::normalisePhone($data['phone_number']);
        $this->assertPhoneLooksReal($phone);

        if (User::query()->where('phone_number', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone_number' => ['Bu raqam bilan hisob allaqachon bor. Kiring.'],
            ]);
        }

        // One transaction: a user without their shop, or a shop without its
        // membership row, is a half-registered account that reads as a bug to
        // whoever hits it next.
        $result = DB::transaction(function () use ($data, $phone) {
            $user = User::create([
                'name' => $data['name'],
                'phone_number' => $phone,
                'password' => $data['password'],
                'last_login_at' => now(),
            ]);

            $shop = Shop::create([
                'name' => $data['shop_name'],
                'phone_number' => $phone,
                'owner_id' => $user->id,
            ]);

            UserShop::create([
                'user_id' => $user->id,
                'shop_id' => $shop->id,
                'role' => UserShop::ROLE_OWNER,
            ]);

            return [$user, $shop];
        });

        [$user, $shop] = $result;

        return response()->json([
            'data' => [
                'token' => $user->createToken('pos-user')->plainTextToken,
                'user' => $this->userPayload($user),
                'shops' => [$this->shopPayload($shop, UserShop::ROLE_OWNER)],
            ],
        ], 201);
    }

    /**
     * Phone and password, the way the login screen already asks for them.
     *
     * Throttled by phone AND by IP. By phone alone, one attacker walking a
     * password list across many accounts never trips it; by IP alone, a whole
     * bazaar behind one NAT locks itself out.
     */
    public function login(Request $request): JsonResponse {
        $data = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
        ]);

        $phone = User::normalisePhone($data['phone_number']);
        $keys = ["pos-login:phone:$phone", 'pos-login:ip:'.$request->ip()];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, 10)) {
                return response()->json([
                    'message' => 'Juda ko\'p urinish. '
                        .ceil(RateLimiter::availableIn($key) / 60).' daqiqadan keyin qayta urining.',
                ], 429);
            }
        }

        $user = User::query()->where('phone_number', $phone)->first();

        if ($user === null || ! $user->checkPassword($data['password'])) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, 900);
            }

            // One message for "no such account" and for "wrong password": the
            // difference tells someone probing which numbers are registered.
            return response()->json(['message' => 'Telefon raqam yoki parol noto\'g\'ri'], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Bu hisob o\'chirilgan. Do\'kon egasiga murojaat qiling.',
            ], 403);
        }

        foreach ($keys as $key) {
            RateLimiter::clear($key);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'data' => [
                'token' => $user->createToken('pos-user')->plainTextToken,
                'user' => $this->userPayload($user),
                'shops' => $this->shopsFor($user),
            ],
        ]);
    }

    /** Who am I, and which shops may I open a till in? */
    public function me(Request $request): JsonResponse {
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
                'shops' => $this->shopsFor($user),
            ],
        ]);
    }

    /**
     * Drop only the token that made this request.
     *
     * Not all of them: a cashier signing out of one till must not sign the
     * owner's phone out of another.
     */
    public function logout(Request $request): JsonResponse {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Uzbek numbers are +998 plus nine digits. Checked because the phone is
     * the login handle and a typo'd one is an account nobody can ever sign
     * back into — there is no email to recover it with.
     */
    private function assertPhoneLooksReal(string $phone): void {
        if (preg_match('/^\+998\d{9}$/', $phone) !== 1) {
            throw ValidationException::withMessages([
                'phone_number' => ['Telefon raqam +998 bilan boshlanib, 9 ta raqamdan iborat bo\'lishi kerak.'],
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function shopsFor(User $user): array {
        return $user->shops()
            ->get()
            ->map(fn (Shop $shop) => $this->shopPayload($shop, (string) $shop->pivot->role))
            ->all();
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone_number' => $user->phone_number,
            'linked_to_pdaftar' => $user->isLinkedToPdaftar(),
        ];
    }

    /** @return array<string, mixed> */
    private function shopPayload(Shop $shop, string $role): array {
        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'role' => $role,
            'terminal_limit' => $shop->terminal_limit,
        ];
    }
}
