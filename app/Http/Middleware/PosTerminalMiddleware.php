<?php

declare(strict_types=1);

namespace Pos\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pos\Models\PosTerminal;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns an authenticated Sanctum token into "this till, in this shop".
 *
 * Every POS endpoint below this middleware can rely on `$request->posTerminal()`
 * being present, active, and belonging to a shop the user still has. That last
 * check is the one that matters: a seller removed from a shop keeps a valid
 * personal token, and without re-checking membership on every request their
 * till would keep selling out of a shop they were fired from.
 */
class PosTerminalMiddleware {
    public function handle(Request $request, Closure $next): Response {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Avtorizatsiya talab qilinadi',
            ], 401);
        }

        $token = $user->currentAccessToken();

        if ($token === null || $token->getKey() === null) {
            return response()->json([
                'success' => false,
                'message' => 'POS terminal tokeni talab qilinadi',
                'code' => 'pos_token_required',
            ], 401);
        }

        /** @var PosTerminal|null $terminal */
        $terminal = PosTerminal::query()
            ->with('shop')
            ->where('access_token_id', $token->getKey())
            ->first();

        if ($terminal === null) {
            return response()->json([
                'success' => false,
                // A plain pDaftar app token lands here. It is a valid token,
                // just not a till's — the client must call /terminals/register
                // with it first.
                'message' => 'Bu token POS terminalga bog\'lanmagan. Avval /terminals/register chaqiring.',
                'code' => 'terminal_not_registered',
            ], 403);
        }

        if (! $terminal->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Bu kassa o\'chirilgan',
                'code' => 'terminal_inactive',
            ], 403);
        }

        $stillInShop = $user->userShops()
            ->where('shop_id', $terminal->shop_id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $stillInShop) {
            return response()->json([
                'success' => false,
                'message' => 'Sizda bu do\'konga kirish huquqi yo\'q',
                'code' => 'shop_access_revoked',
            ], 403);
        }

        $request->attributes->set('pos_terminal', $terminal);

        $response = $next($request);

        // Stamped after the handler so a request that 500s does not read as a
        // healthy heartbeat on the terminals screen. Written with a bare
        // UPDATE — this fires on every request and must not carry the cost or
        // the observer side effects of a model save.
        PosTerminal::whereKey($terminal->id)->update(['last_seen_at' => now()]);

        return $response;
    }
}
