<?php

declare(strict_types=1);

namespace Pos\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a token ability on a POS route: `pos.scope:pos:sales.write`.
 *
 * This is the ONLY thing standing between a third-party till and our stock
 * ledger. AliPOS holds a token minted with read + sell abilities, so its POST
 * to /stock/movements is refused here even though the underlying user — a real
 * pDaftar shop owner — would be perfectly entitled to do it from the app.
 * Narrowing, never widening: the user's own shop permissions are still checked
 * downstream.
 */
class PosScopeMiddleware {
    public function handle(Request $request, Closure $next, string ...$scopes): Response {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            return response()->json([
                'success' => false,
                'message' => 'Avtorizatsiya talab qilinadi',
            ], 401);
        }

        foreach ($scopes as $scope) {
            if ($token->can($scope)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Bu amal uchun ruxsat yo\'q',
            'code' => 'scope_missing',
            'required_scopes' => array_values($scopes),
        ], 403);
    }
}
