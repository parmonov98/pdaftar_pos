<?php

declare(strict_types=1);

namespace Pos\Services;

use Illuminate\Support\Facades\DB;
use Pos\Constants\PosProvider;
use Pos\Constants\PosScope;
use Pos\Exceptions\BusinessException;
use Pos\Models\PosTerminal;
use Pos\Models\Shop;
use Pos\Models\User;
use Pos\Models\UserShop;

/**
 * Registering, re-registering and revoking tills.
 */
class PosTerminalService {
    /**
     * Provision a device and mint its token.
     *
     * This is NOT a "create a kassa" step and must never be presented as one.
     * pDaftar's access model is already per-person: a shop invites sellers, each
     * has their own phone and password, and any of them can open the business.
     * The POS inherits exactly that — Anvar signs in as Anvar and sells, Sobir
     * signs in as Sobir and sells. Nobody registers a till, and there is no
     * separate thing to run out of.
     *
     * The row still exists, for two reasons that are invisible to the user:
     * offline idempotency is scoped per device (two phones may mint the same
     * operation counter), and a sale is worth attributing to the machine it was
     * rung up on. Both are bookkeeping, not a licence.
     *
     * Re-provisioning is the normal case — a browser clears storage, a device is
     * handed to another seller. Keying on (shop, device_id) reuses the row, and
     * the previous token is revoked so a re-provisioned device leaves no working
     * credential behind.
     *
     * @return array{terminal: PosTerminal, token: string}
     *
     * @throws BusinessException
     */
    public function register(
        User $user,
        Shop $shop,
        string $deviceId,
        string $name,
        PosProvider $provider,
    ): array {
        $this->assertUserBelongsToShop($user, $shop);

        return DB::transaction(function () use ($user, $shop, $deviceId, $name, $provider) {
            /** @var PosTerminal|null $terminal */
            $terminal = PosTerminal::withTrashed()
                ->where('shop_id', $shop->id)
                ->where('device_id', $deviceId)
                ->lockForUpdate()
                ->first();

            if ($terminal !== null) {
                $terminal->restore();
                $this->revokeToken($terminal);
            }

            $terminal ??= new PosTerminal([
                'shop_id' => $shop->id,
                'device_id' => $deviceId,
            ]);

            $terminal->fill([
                'shop_id' => $shop->id,
                'device_id' => $deviceId,
                'user_id' => $user->id,
                'name' => $name,
                'provider' => $provider->value,
                'is_active' => true,
                'last_seen_at' => now(),
            ]);
            $terminal->save();

            // Abilities are the provider ceiling, not a request parameter: a
            // client asking for its own scopes would make the ceiling
            // decorative.
            $newToken = $user->createToken(
                'pos:'.$provider->value.':'.$deviceId,
                PosScope::defaultFor($provider),
            );

            $terminal->access_token_id = $newToken->accessToken->getKey();
            $terminal->save();

            return ['terminal' => $terminal, 'token' => $newToken->plainTextToken];
        });
    }

    /**
     * Deactivate a till and kill its credential, freeing its kassa slot.
     */
    public function revoke(PosTerminal $terminal): void {
        DB::transaction(function () use ($terminal) {
            $this->revokeToken($terminal);
            $terminal->is_active = false;
            $terminal->save();
        });
    }

    /**
     * How many devices are currently signed in for this shop.
     *
     * Reported so an owner can see their sellers' devices; it is NOT a quota.
     * There used to be a limit here equal to the shop's seat count, and it was
     * wrong twice over: it invented a resource pDaftar does not have, and it
     * blocked the ordinary case of one seller using a phone and the shop
     * tablet — something the mobile app has always allowed. Access is governed
     * entirely by whether the person can sign in to the shop.
     */
    public function activeTerminalCount(Shop $shop): int {
        return PosTerminal::query()
            ->where('shop_id', $shop->id)
            ->where('is_active', true)
            ->count();
    }

    /** @throws BusinessException */
    private function assertUserBelongsToShop(User $user, Shop $shop): void {
        $belongs = UserShop::query()
            ->where('user_id', $user->id)
            ->where('shop_id', $shop->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $belongs) {
            throw new BusinessException('Bu do\'konga kirish huquqingiz yo\'q');
        }
    }

    private function revokeToken(PosTerminal $terminal): void {
        if ($terminal->access_token_id === null) {
            return;
        }

        $terminal->user->tokens()->whereKey($terminal->access_token_id)->delete();
        $terminal->access_token_id = null;
    }
}
