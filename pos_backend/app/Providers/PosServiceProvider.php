<?php

declare(strict_types=1);

namespace Pos\Providers;

use App\Models\Debt;
use Pos\Models\PersonalAccessToken;
use App\Models\Repayment;
use App\Models\SupplierTransaction;
use App\Observers\DebtObserver;
use App\Observers\RepaymentObserver;
use App\Observers\SupplierTransactionObserver;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * The parts of pDaftar's boot sequence a POS sale actually depends on.
 *
 * pDaftar's own AppServiceProvider is not loaded — see bootstrap/providers.php
 * for why — so anything it wires up that a sale needs has to be wired here too.
 * This is the single most dangerous file in the split, because everything it
 * forgets fails SILENTLY:
 *
 *   - without RepaymentObserver a POS sale writes the debt, the stock movement
 *     and the repayment, and the money never appears in Kassa Kirim. No error.
 *     Nobody notices until someone reconciles the month.
 *   - without the custom PersonalAccessToken model, Sanctum falls back to its
 *     own and the device columns on the token silently stop being written.
 *
 * So the observers are registered here AND verified — see PosIntegrityService,
 * which the /health endpoint and a boot-time check both run. A missing observer
 * becomes a red health check instead of a hole in the books.
 */
class PosServiceProvider extends ServiceProvider {
    /**
     * Observers a sale's write path depends on.
     *
     * Kept as data rather than a series of calls so PosIntegrityService can
     * assert the exact same list is attached, instead of the two drifting.
     *
     * @var array<class-string, class-string>
     */
    public const OBSERVERS = [
        // Bookkeeping on the debt itself (client end_action_at, balances).
        Debt::class => DebtObserver::class,
        // → ShopIncomeSyncService → Kassa Kirim. The one that matters most.
        Repayment::class => RepaymentObserver::class,
        // Supplier chiqim → Kassa mirror, for the prixod operations.
        SupplierTransaction::class => SupplierTransactionObserver::class,
    ];

    public function register(): void {
        //
    }

    public function boot(): void {
        // Same custom model pDaftar registers, so `device_name`/`platform` on a
        // terminal token keep being populated and a token issued by either app
        // is readable by the other.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        foreach (self::OBSERVERS as $model => $observer) {
            $model::observe($observer);
        }
    }
}
