<?php

declare(strict_types=1);

namespace Pos\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Pos\Models\PersonalAccessToken;

/**
 * The POS's own wiring.
 *
 * This file used to register three of pDaftar's observers — DebtObserver,
 * RepaymentObserver, SupplierTransactionObserver — because a POS sale went
 * through pDaftar's write path and that path is only correct with them
 * attached. It was described, accurately, as the most dangerous file in the
 * split: forgetting one meant a sale wrote the debt and the stock movement
 * while the money never reached Kassa, with no error anywhere.
 *
 * None of that applies now. The POS writes its own tables through its own
 * code, so the observers it needs are the ones it defines itself, and the
 * consequences of forgetting one are its own to own.
 */
class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Sanctum resolves EVERY authenticated request through this model, so
        // it must be the POS's own — pointing it at pDaftar's would make
        // authentication impossible without a checkout of pDaftar present.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
