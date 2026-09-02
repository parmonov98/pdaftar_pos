<?php

declare(strict_types=1);

namespace Pos\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Pos\Services\PosIntegrityService;

/**
 * Is this deployment wired up correctly?
 *
 * Deliberately unauthenticated: it reports no shop data, and the people who need
 * it most — a load balancer, a deploy script, whoever is on the phone at 8am
 * because the till stopped working — do not have a token. It returns 503 when
 * anything is broken so a health check can act on it without parsing the body.
 *
 * Extends Laravel's base controller rather than pDaftar's, because pDaftar's
 * pulls in shop-permission traits this endpoint has no use for.
 */
class PosHealthController extends Controller {
    public function __invoke(PosIntegrityService $integrity): JsonResponse {
        $result = $integrity->run();

        return response()->json([
            'ok' => $result['ok'],
            'service' => 'pdaftar-pos-backend',
            'checks' => $result['checks'],
            'server_time' => now()->toIso8601String(),
        ], $result['ok'] ? 200 : 503);
    }
}
