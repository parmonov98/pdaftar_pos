<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pos\Constants\PosScope;
use Pos\Http\Controllers\AuthController;
use Pos\Http\Controllers\PosHealthController;
use Pos\Http\Controllers\PosOperationController;
use Pos\Http\Controllers\PosSaleHistoryController;
use Pos\Http\Controllers\PosSyncController;
use Pos\Http\Controllers\PosTerminalController;

/*
|--------------------------------------------------------------------------
| POS Integration API — /api/pos/v1
|--------------------------------------------------------------------------
|
| One API for every till: pDaftar POS and third-party systems (AliPOS, YesPOS,
| ...) authenticate the same way and speak the same operation vocabulary. There
| is deliberately no privileged path for our own POS — an integration API whose
| author's client bypasses it stops being an integration API within two releases.
|
| Auth is two-staged:
|   1. /terminals/register is reached with an ordinary pDaftar USER token and
|      hands back a TERMINAL token.
|   2. Everything else needs that terminal token, which is what binds a request
|      to one shop and one device.
|
| Scopes are enforced twice on writes: `pos.scope` rejects the request early
| with a clear message, and PosOperationDispatcher re-checks per operation type
| because a /sync/push batch carries many types behind one route.
|
*/

/*
 * Unauthenticated on purpose. It exposes no shop data, and the people who need
 * it — a load balancer, a deploy script, whoever is on the phone because the
 * till stopped — have no token. Returns 503 when the link to pDaftar's shared
 * domain is broken, so a monitor can act on the status code alone.
 */
Route::get('/health', PosHealthController::class)->name('pos.health');

/*
 * Signing in to the POS itself.
 *
 * Unauthenticated because they are how a client gets its first credential.
 * Both are rate limited inside AuthController, by phone and by IP.
 *
 * These exist because the POS now has its own users: the till used to post to
 * pDaftar's /api/mobile/login, which is not a route this application has and,
 * on a separate database, could not have been validated if it were.
 */
Route::post('/auth/register', [AuthController::class, 'register'])->name('pos.auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('pos.auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('pos.auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('pos.auth.logout');

    // Stage 1 — the only endpoints an un-provisioned client can reach.
    //
    // The manage pair is here, on the USER token, and not behind
    // `pos.terminal` on purpose: freeing a kassa slot must be possible for
    // someone who has no terminal token yet. Behind the terminal middleware
    // the limit would be a trap — you would need a till to be able to make
    // room for a till.
    Route::post('/terminals/register', [PosTerminalController::class, 'register'])
        ->name('pos.terminals.register');
    Route::get('/terminals/manage', [PosTerminalController::class, 'manageIndex'])
        ->name('pos.terminals.manage.index');
    Route::delete('/terminals/manage/{terminal}', [PosTerminalController::class, 'manageDestroy'])
        ->whereNumber('terminal')
        ->name('pos.terminals.manage.destroy');

    // Stage 2 — everything below requires a registered, active terminal.
    Route::middleware('pos.terminal')->group(function () {
        Route::get('/me', [PosTerminalController::class, 'me'])->name('pos.me');

        Route::get('/terminals', [PosTerminalController::class, 'index'])->name('pos.terminals.index');
        Route::delete('/terminals/{terminal}', [PosTerminalController::class, 'destroy'])
            ->whereNumber('terminal')
            ->name('pos.terminals.destroy');

        // ─── O'qish ───
        Route::middleware('pos.scope:'.PosScope::CATALOG_READ->value)->group(function () {
            // Declared before any '/sales' POST group so the literal
            // '/sales/recent' path is never shadowed by a parameterised one.
            Route::get('/sales/recent', [PosSaleHistoryController::class, 'recent'])->name('pos.sales.recent');
            Route::get('/sync/pull', [PosSyncController::class, 'pull'])->name('pos.sync.pull');
            Route::get('/sync/status', [PosSyncController::class, 'status'])->name('pos.sync.status');
            Route::get('/products/lookup', [PosSyncController::class, 'lookup'])->name('pos.products.lookup');
        });

        // ─── Offline queue ───
        // No single scope guards this route: one batch may carry sale.create
        // and stock.movement together, so the check belongs per operation,
        // inside the dispatcher.
        Route::post('/sync/push', [PosSyncController::class, 'push'])->name('pos.sync.push');

        // ─── Single writes ───
        Route::middleware('pos.scope:'.PosScope::SALES_WRITE->value)->group(function () {
            Route::post('/sales', [PosOperationController::class, 'sale'])->name('pos.sales.store');
            Route::post('/sales/cancel', [PosOperationController::class, 'cancelSale'])->name('pos.sales.cancel');
        });

        Route::middleware('pos.scope:'.PosScope::PRODUCTS_WRITE->value)->group(function () {
            Route::post('/products', [PosOperationController::class, 'createProduct'])->name('pos.products.store');
            Route::patch('/products', [PosOperationController::class, 'updateProduct'])->name('pos.products.update');
        });

        Route::middleware('pos.scope:'.PosScope::STOCK_WRITE->value)->group(function () {
            Route::post('/stock/movements', [PosOperationController::class, 'stockMovement'])->name('pos.stock.movements.store');
            Route::post('/stock/stocktake', [PosOperationController::class, 'stocktake'])->name('pos.stock.stocktake');
        });
    });
});
