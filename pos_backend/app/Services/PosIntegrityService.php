<?php

declare(strict_types=1);

namespace Pos\Services;

use App\Models\Debt;
use App\Models\Repayment;
use App\Models\Shop;
use App\UseCases\Debt\StoreDebtUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Pos\Providers\PosServiceProvider;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Does this deployment still hang together?
 *
 * Splitting the POS into its own application buys independent deploys and
 * independent failure, and it costs a set of invisible couplings: the shared
 * domain must be present and the same version, the observers pDaftar registers
 * must be registered here too, the queue must be the one pDaftar's workers read,
 * and both apps must be pointed at the same database.
 *
 * Every one of those breaks QUIETLY. A missing observer does not throw — the
 * sale is written and the cash simply never reaches Kassa. A mismatched Redis
 * prefix does not throw — the balance job is queued into a namespace nobody
 * consumes. Weeks later the books are wrong and there is no error to grep for.
 *
 * So each coupling is asserted, out loud, here. This is what makes the split
 * safe rather than merely tidy: run it in CI, hit it after every deploy, and a
 * broken link is a red check instead of a hole in the accounts.
 */
class PosIntegrityService {
    /**
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>}
     */
    public function run(): array {
        $checks = [
            $this->sharedDomainPresent(),
            $this->saleUseCaseContract(),
            $this->observersAttached(),
            $this->database(),
            $this->queue(),
            $this->posTables(),
        ];

        return [
            'ok' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
        ];
    }

    /**
     * The shared domain is reachable at all.
     *
     * If ../backend is missing — a deploy that shipped only this directory —
     * every other check would fail with a confusing autoload error. This one
     * names the actual problem.
     */
    private function sharedDomainPresent(): array {
        $missing = [];

        foreach ([Debt::class, Repayment::class, Shop::class, StoreDebtUseCase::class] as $class) {
            if (! class_exists($class)) {
                $missing[] = $class;
            }
        }

        return $this->check(
            'shared_domain',
            $missing === [],
            $missing === []
                ? 'pDaftar domeni yuklandi'
                : 'Topilmadi: '.implode(', ', $missing).'. pdaftar.backend shu repo yonida (sibling) turibdimi?',
        );
    }

    /**
     * StoreDebtUseCase still accepts what a POS sale needs to pass it.
     *
     * A POS sale relies on two additions to the shared use case:
     * `forceAllowNegativeStock` (a till's sale cannot be refused for stock) and
     * a `date` on the DTO (an offline sale belongs to the day it happened).
     * If someone removes either while refactoring pDaftar, sales would keep
     * working and start being wrong — oversells refused at sync time, or every
     * offline sale landing on today. Checked by reflection rather than trusted.
     */
    private function saleUseCaseContract(): array {
        try {
            $params = (new ReflectionClass(StoreDebtUseCase::class))
                ->getMethod('execute')
                ->getParameters();

            $names = array_map(fn ($p) => $p->getName(), $params);
            $hasForce = in_array('forceAllowNegativeStock', $names, true);

            $dtoType = $params[0]->getType();
            $dtoClass = $dtoType instanceof ReflectionNamedType ? $dtoType->getName() : null;
            $hasDate = $dtoClass !== null && method_exists($dtoClass, 'getDate');

            $problems = [];
            if (! $hasForce) {
                $problems[] = 'execute() da forceAllowNegativeStock yo\'q';
            }
            if (! $hasDate) {
                $problems[] = 'StoreDebtDTO da getDate() yo\'q';
            }

            return $this->check(
                'sale_contract',
                $problems === [],
                $problems === [] ? 'StoreDebtUseCase kutilgan shaklda' : implode('; ', $problems),
            );
        } catch (Throwable $e) {
            return $this->check('sale_contract', false, $e->getMessage());
        }
    }

    /**
     * Every observer a sale's write path needs is actually attached.
     *
     * THE check. Without RepaymentObserver the money never reaches Kassa and
     * nothing anywhere reports a problem.
     */
    private function observersAttached(): array {
        $missing = [];

        foreach (PosServiceProvider::OBSERVERS as $model => $observer) {
            /** @var Model $instance */
            $instance = new $model;

            // Observers register as listeners on eloquent.{event}: {model}.
            // "created" is enough: an observer is attached wholesale or not.
            $event = 'eloquent.created: '.$model;

            $attached = collect($instance->getEventDispatcher()->getListeners($event))
                ->contains(function ($listener) use ($observer) {
                    // Laravel wraps class-based observers in a closure; the
                    // observer's name survives in the bound "class@method"
                    // string it closes over, so reflect it out.
                    try {
                        $r = new \ReflectionFunction($listener);
                        $vars = $r->getStaticVariables();

                        return isset($vars['listener']) && str_contains((string) $vars['listener'], $observer);
                    } catch (Throwable) {
                        return false;
                    }
                });

            if (! $attached) {
                $missing[] = class_basename($observer);
            }
        }

        return $this->check(
            'observers',
            $missing === [],
            $missing === []
                ? count(PosServiceProvider::OBSERVERS).' ta observer ulangan (Kassa mirror ishlaydi)'
                : 'ULANMAGAN: '.implode(', ', $missing).' — sotuv yoziladi, lekin Kassaga pul tushmaydi!',
        );
    }

    /** Same database as pDaftar, and reachable. */
    private function database(): array {
        try {
            $name = DB::connection()->getDatabaseName();
            $shops = Shop::query()->count();

            // An empty shops table means this is pointed at a fresh database
            // rather than pDaftar's — the POS has nothing to sell from and
            // would fail per-request instead of saying so once.
            return $this->check(
                'database',
                $shops > 0,
                $shops > 0
                    ? "`{$name}` · {$shops} do'kon"
                    : "`{$name}` bo'sh — pDaftar bazasiga ulanmagan bo'lishi mumkin",
            );
        } catch (Throwable $e) {
            return $this->check('database', false, $e->getMessage());
        }
    }

    /**
     * The queue this app pushes to is the one pDaftar's workers read.
     *
     * StoreDebtUseCase dispatches ProcessDebtBalanceAndSms, which is what
     * recomputes the client balance after a nasiya sale. Redis keys are
     * namespaced by prefix, and the default prefix is derived from APP_NAME — so
     * two apps with different names queue into two different namespaces and
     * pDaftar's horizon never sees ours. Nothing errors; balances just stop
     * updating.
     */
    private function queue(): array {
        try {
            $ours = (string) config('database.redis.options.prefix');
            $expected = (string) config('pos.expected_redis_prefix');

            if ($expected === '') {
                return $this->check(
                    'queue_prefix',
                    false,
                    'POS_EXPECTED_REDIS_PREFIX sozlanmagan — pDaftar bilan bir navbatda ekanini tekshirib bo\'lmaydi',
                );
            }

            Redis::connection()->ping();

            return $this->check(
                'queue_prefix',
                $ours === $expected,
                $ours === $expected
                    ? "prefiks `{$ours}` — pDaftar bilan bir xil"
                    : "prefiks `{$ours}` != pDaftar `{$expected}` — balans joblari boshqa navbatga tushadi!",
            );
        } catch (Throwable $e) {
            return $this->check('queue_prefix', false, $e->getMessage());
        }
    }

    /** The two tables this app owns exist. */
    private function posTables(): array {
        try {
            $missing = array_values(array_filter(
                ['pos_terminals', 'pos_operations'],
                fn (string $t) => ! DB::getSchemaBuilder()->hasTable($t),
            ));

            return $this->check(
                'pos_tables',
                $missing === [],
                $missing === [] ? 'pos_terminals, pos_operations bor' : 'Yo\'q: '.implode(', ', $missing).' — `php artisan migrate` kerak',
            );
        } catch (Throwable $e) {
            return $this->check('pos_tables', false, $e->getMessage());
        }
    }

    private function check(string $name, bool $ok, string $detail): array {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
