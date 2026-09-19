<?php

use Pos\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Versioned in the path from day one: a till in a shop is updated when
        // someone drives there, so v1 has to keep answering long after v2 ships.
        api: __DIR__.'/../routes/pos_api.php',
        apiPrefix: 'api/pos/v1',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Bearer tokens only — no session, no cookies, so
        // EnsureFrontendRequestsAreStateful is deliberately absent. With it, a
        // browser-based POS on the same domain could be authenticated as
        // whoever's session cookie happened to be present.
        $middleware->api(remove: [
            \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        ]);

        $middleware->alias([
            'pos.terminal' => \Pos\Http\Middleware\PosTerminalMiddleware::class,
            'pos.scope' => \Pos\Http\Middleware\PosScopeMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A till only ever wants JSON. Left to Laravel's defaults, a
        // ModelNotFound would render an HTML error page that the outbox would
        // store as an opaque failure.
        $exceptions->dontReport(BusinessException::class);

        $exceptions->render(function (BusinessException $e, Request $request) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Ma\'lumot topilmadi',
            ], 404);
        });
    })
    ->create();
