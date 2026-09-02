<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use App\Exceptions\Custom\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Pos\Constants\PosProvider;
use Pos\Constants\PosScope;
use Pos\Models\PosTerminal;
use Pos\Services\PosTerminalService;

/**
 * Signing a device in, and listing the devices a shop has signed in.
 *
 * `register` is the ONLY endpoint reached with an ordinary pDaftar user token;
 * everything else in the POS API needs the device token it returns. That
 * separation is deliberate — the user's phone token and the device's token have
 * very different lifetimes and blast radii, and one should never be usable as
 * the other.
 *
 * Note what this is NOT: there is no "create a kassa" concept. Whoever can sign
 * in to the shop in pDaftar can sell in the POS, because that is already how
 * the product works — a shop invites sellers and each has their own phone and
 * password. `register` is a silent device handshake the client makes right
 * after login; the seller never sees it and can never be refused by it.
 */
class PosTerminalController extends Controller {
    public function __construct(private readonly PosTerminalService $terminals) {}

    /**
     * @OA\Post(
     *     path="/api/pos/v1/terminals/register",
     *     summary="Qurilmani ulash va token olish (jimgina)",
     *     description="
     * Login qilingandan keyin darhol, avtomatik chaqiriladi. Sotuvchi buni ko'rmaydi va
     * bu yerda **hech qanday limit yo'q** — do'konga kira oladigan har bir user POS'da ham sotadi.
     *
     * `name` yuborilmasa, user ismi va brauzer bo'yicha o'zi qo'yiladi.
     * Bir xil `device_id` bilan qayta chaqirish eski tokenni bekor qilib, yangisini beradi.
     * ",
     *     tags={"POS Terminal"},
     *     security={{"posToken":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"shop_id","device_id"},
     *
     *             @OA\Property(property="shop_id", type="integer", example=1),
     *             @OA\Property(property="device_id", type="string", example="web-a3f9…", description="Qurilmaning barqaror identifikatori (localStorage'da saqlanadi)"),
     *             @OA\Property(property="name", type="string", nullable=true, description="Ixtiyoriy. Yuborilmasa avtomatik: 'Anvar · Chrome'"),
     *             @OA\Property(property="provider", type="string", enum={"pdaftar_pos","alipos","yespos","other"}, default="pdaftar_pos")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Ulandi"),
     *     @OA\Response(response=403, description="Do'konga kirish huquqi yo'q"),
     *     @OA\Response(response=422, description="Validatsiya xatosi")
     * )
     */
    public function register(Request $request): JsonResponse {
        $data = $request->validate([
            'shop_id' => 'required|integer|exists:shops,id',
            'device_id' => 'required|string|max:128',
            // Optional: the seller is never asked to name anything. A blank
            // name is filled in from who they are and what they are using.
            'name' => 'nullable|string|max:120',
            'provider' => 'nullable|string|in:'.implode(',', PosProvider::values()),
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var Shop $shop */
        $shop = Shop::findOrFail($data['shop_id']);

        $provider = PosProvider::from($data['provider'] ?? PosProvider::PDAFTAR_POS->value);

        try {
            $result = $this->terminals->register(
                $user,
                $shop,
                trim($data['device_id']),
                $this->deviceName($request, $user, $data['name'] ?? null),
                $provider,
            );
        } catch (BusinessException $e) {
            // The only refusal left is "you are not in this shop", which is a
            // genuine access answer rather than a quota.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'shop_forbidden',
            ], 403);
        }

        /** @var PosTerminal $terminal */
        $terminal = $result['terminal'];

        return response()->json([
            'success' => true,
            'data' => [
                // Shown once and never again — Sanctum only ever hands out the
                // plaintext at creation. A device that loses it re-registers.
                'token' => $result['token'],
                'terminal' => $this->terminalArray($terminal),
                'scopes' => PosScope::defaultFor($provider),
            ],
        ], 201);
    }

    /**
     * A name for the device, so the owner's device list reads like people and
     * machines rather than a column of UUIDs.
     *
     * Derived rather than asked for: making a seller invent a name is the
     * "create a kassa" step this design exists to remove, and the two facts
     * worth recording — who signed in and on what — are both already known.
     */
    private function deviceName(Request $request, User $user, ?string $provided): string {
        $provided = trim((string) $provided);

        if ($provided !== '') {
            return $provided;
        }

        $who = trim((string) ($user->name ?? '')) ?: (string) $user->phone_number;

        $agent = (string) $request->userAgent();
        $what = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'POS',
        };

        return mb_substr(trim($who.' · '.$what), 0, 120);
    }

    /**
     * @OA\Get(
     *     path="/api/pos/v1/terminals/manage",
     *     summary="Do'konda POS ochgan qurilmalar ro'yxati",
     *     description="Egasi uchun: kim, qaysi qurilmadan, qachon ulangan. Limit emas — shunchaki ko'rinish. Kerakmas qurilmani bu yerdan uzib qo'ysa bo'ladi.",
     *     tags={"POS Terminal"},
     *     security={{"posToken":{}}},
     *
     *     @OA\Parameter(name="shop_id", in="query", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=403, description="Do'konga ruxsat yo'q")
     * )
     *
     * Listing and disconnecting devices with the USER token, not a device token.
     *
     * On the user token because the owner asking "what is signed in to my shop?"
     * is asking as themselves, from their phone or from a device that may not be
     * signed in to the POS at all.
     */
    public function manageIndex(Request $request): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $shopId = (int) $request->query('shop_id', '0');

        if (! $this->userBelongsToShop($user, $shopId)) {
            return response()->json([
                'success' => false,
                'message' => 'Bu do\'konga kirish huquqingiz yo\'q',
            ], 403);
        }

        /** @var Shop $shop */
        $shop = Shop::findOrFail($shopId);

        $terminals = PosTerminal::query()
            ->where('shop_id', $shopId)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $terminals->map(fn (PosTerminal $t) => $this->terminalArray($t))->all(),
            'meta' => [
                'devices' => $this->terminals->activeTerminalCount($shop),
            ],
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/pos/v1/terminals/manage/{terminal}",
     *     summary="Qurilmani uzish (user tokeni bilan)",
     *     description="Qurilma tokeni bekor qilinadi, u qayta kirishi kerak bo'ladi. Sotuv tarixi saqlanadi.",
     *     tags={"POS Terminal"},
     *     security={{"posToken":{}}},
     *
     *     @OA\Parameter(name="terminal", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="O'chirildi"),
     *     @OA\Response(response=403, description="Ruxsat yo'q"),
     *     @OA\Response(response=404, description="Topilmadi")
     * )
     */
    public function manageDestroy(Request $request, int $terminalId): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        /** @var PosTerminal|null $terminal */
        $terminal = PosTerminal::query()->whereKey($terminalId)->first();

        if ($terminal === null) {
            return response()->json(['success' => false, 'message' => 'Qurilma topilmadi'], 404);
        }

        // Membership of the till's OWN shop — not of whatever shop_id the
        // caller passed. Trusting a query parameter here would let any user
        // revoke any terminal by naming a shop they happen to belong to.
        if (! $this->userBelongsToShop($user, (int) $terminal->shop_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Bu qurilmani uzish huquqingiz yo\'q',
            ], 403);
        }

        $this->terminals->revoke($terminal);

        return response()->json([
            'success' => true,
            'message' => 'Qurilma uzildi',
            'meta' => [
                'devices' => $this->terminals->activeTerminalCount($terminal->shop),
            ],
        ]);
    }

    private function userBelongsToShop(User $user, int $shopId): bool {
        if ($shopId <= 0) {
            return false;
        }

        return $user->userShops()
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * @OA\Get(
     *     path="/api/pos/v1/me",
     *     summary="Joriy sotuvchi, qurilma va do'kon sozlamalari",
     *     description="POS ishga tushganda chaqiriladi: kim sotyapti, qaysi do'kon, qanday scope'lar, valyuta va ombor sozlamalari.",
     *     tags={"POS Terminal"},
     *     security={{"posToken":{}}},
     *
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function me(Request $request): JsonResponse {
        /** @var PosTerminal $terminal */
        $terminal = $request->attributes->get('pos_terminal');
        /** @var User $user */
        $user = $request->user();
        $shop = $terminal->shop;

        return response()->json([
            'success' => true,
            'data' => [
                'terminal' => $this->terminalArray($terminal),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name ?? null,
                    'phone_number' => $user->phone_number ?? null,
                ],
                'shop' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                    'currency_id' => $shop->currency_id ?? null,
                    // The till shows this so a cashier knows whether an
                    // oversell will be blocked — but note POS sales always
                    // proceed regardless (see StoreDebtUseCase's
                    // forceAllowNegativeStock). It is informational.
                    'allow_negative_stock' => (bool) ($shop->allow_negative_stock ?? true),
                    'low_stock_threshold' => $shop->low_stock_threshold ?? null,
                ],
                'scopes' => $this->scopesOf($user),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/pos/v1/terminals",
     *     summary="Do'kondagi barcha qurilmalar",
     *     tags={"POS Terminal"},
     *     security={{"posToken":{}}},
     *
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function index(Request $request): JsonResponse {
        /** @var PosTerminal $terminal */
        $terminal = $request->attributes->get('pos_terminal');

        $all = PosTerminal::query()
            ->where('shop_id', $terminal->shop_id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $all->map(fn (PosTerminal $t) => $this->terminalArray($t))->all(),
            'meta' => [
                'devices' => $this->terminals->activeTerminalCount($terminal->shop),
            ],
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/pos/v1/terminals/{terminal}",
     *     summary="Qurilmani uzish (tokenini bekor qilish)",
     *     description="Qurilma tokeni bekor qilinadi. Sotuv tarixi saqlanib qoladi.",
     *     tags={"POS Terminal"},
     *     security={{"posToken":{}}},
     *
     *     @OA\Parameter(name="terminal", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="O'chirildi"),
     *     @OA\Response(response=404, description="Topilmadi")
     * )
     */
    public function destroy(Request $request, int $terminalId): JsonResponse {
        /** @var PosTerminal $current */
        $current = $request->attributes->get('pos_terminal');

        /** @var PosTerminal|null $target */
        $target = PosTerminal::query()
            ->where('shop_id', $current->shop_id)
            ->whereKey($terminalId)
            ->first();

        if ($target === null) {
            return response()->json(['success' => false, 'message' => 'Qurilma topilmadi'], 404);
        }

        $this->terminals->revoke($target);

        return response()->json([
            'success' => true,
            'message' => 'Qurilma uzildi',
            'meta' => [
                'devices' => $this->terminals->activeTerminalCount($current->shop),
            ],
        ]);
    }

    /**
     * The abilities on the token this request arrived with.
     *
     * @return string[]
     */
    private function scopesOf(User $user): array {
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return [];
        }

        return is_array($token->abilities) ? $token->abilities : [];
    }

    private function terminalArray(PosTerminal $terminal): array {
        return [
            'id' => $terminal->id,
            'name' => $terminal->name,
            'device_id' => $terminal->device_id,
            'provider' => $terminal->provider->value,
            'shop_id' => $terminal->shop_id,
            'user_id' => $terminal->user_id,
            'is_active' => $terminal->is_active,
            'last_seen_at' => $terminal->last_seen_at?->toIso8601String(),
            'last_sync_at' => $terminal->last_sync_at?->toIso8601String(),
        ];
    }
}
