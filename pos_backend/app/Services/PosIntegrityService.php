<?php

declare(strict_types=1);

namespace Pos\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Is this POS actually able to work?
 *
 * Every check here used to be about pDaftar: does ../backend load, does
 * StoreDebtUseCase still have the signature we call it with, are pDaftar's
 * observers attached, do we share pDaftar's Redis prefix. Those questions
 * mattered when a POS sale was written by pDaftar's code into pDaftar's
 * database. They are meaningless now and were actively misleading — the
 * sale_contract check was reporting a real drift in a call path that no longer
 * exists.
 *
 * What replaces them is narrower and true: the POS's own database, its own
 * schema, its own queue. The rule is unchanged — every failure mode here is
 * one that otherwise breaks silently, which is why they are asserted rather
 * than assumed.
 */
class PosIntegrityService {
    /** @return array{ok: bool, checks: array<int, array{name: string, ok: bool, detail: string}>} */
    public function run(): array {
        $checks = [
            $this->database(),
            $this->schema(),
            $this->queue(),
            $this->logging(),
        ];

        return [
            'ok' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
        ];
    }

    /** The database answers at all. */
    private function database(): array {
        try {
            DB::connection()->getPdo();

            return $this->check(
                'database',
                true,
                'ulandi: '.(string) DB::connection()->getDatabaseName(),
            );
        } catch (Throwable $e) {
            return $this->check('database', false, $e->getMessage());
        }
    }

    /**
     * Every table this application owns is present.
     *
     * A missing table is not a 500 in an obvious place: `users` missing means
     * nobody can sign in, `personal_access_tokens` missing means tokens are
     * issued and then rejected on the next request, which reads as "the till
     * keeps logging me out" rather than as a failed migration.
     */
    private function schema(): array {
        try {
            $required = [
                'users',
                'shops',
                'user_shop',
                'personal_access_tokens',
                'pos_terminals',
                'pos_operations',
            ];

            $missing = array_values(array_filter(
                $required,
                fn (string $t) => ! DB::getSchemaBuilder()->hasTable($t),
            ));

            return $this->check(
                'schema',
                $missing === [],
                $missing === []
                    ? count($required).' ta jadval joyida'
                    : 'Yo\'q: '.implode(', ', $missing).' — `php artisan migrate` kerak',
            );
        } catch (Throwable $e) {
            return $this->check('schema', false, $e->getMessage());
        }
    }

    /**
     * Redis answers.
     *
     * It backs the cache, the session and the queue. When it is down the app
     * still boots and still serves the health endpoint, so without this check
     * the first sign of trouble is a login that hangs.
     */
    private function queue(): array {
        try {
            Redis::connection()->ping();

            return $this->check('queue', true, 'redis javob berdi');
        } catch (Throwable $e) {
            return $this->check('queue', false, $e->getMessage());
        }
    }

    /**
     * The application can actually write its log.
     *
     * Checked because the failure is invisible by construction: php-fpm runs
     * as one user, the bind-mounted directory is owned by another, Laravel
     * cannot append, and the write it cannot make is the record of what went
     * wrong. Requests keep answering 500 with an empty log, and the only way
     * to see the exception is to reproduce it by hand inside the container.
     * This box ran that way for a day before anyone noticed.
     */
    private function logging(): array {
        try {
            $dir = storage_path('logs');

            if (! is_dir($dir)) {
                return $this->check('logging', false, "storage/logs yo'q");
            }

            if (! is_writable($dir)) {
                // The running user is named because it is the whole answer:
                // the directory is fine, the process is simply not the one
                // that owns it. posix_* is not loaded everywhere, so this
                // falls back rather than becoming a second failure.
                $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'nomalum')
                    : (get_current_user() ?: 'nomalum');

                return $this->check(
                    'logging',
                    false,
                    "storage/logs yozib bo'lmaydi ({$user} sifatida ishlayapti) — xatolar hech qayerga yozilmaydi",
                );
            }

            return $this->check('logging', true, 'storage/logs yoziladi');
        } catch (Throwable $e) {
            return $this->check('logging', false, $e->getMessage());
        }
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function check(string $name, bool $ok, string $detail): array {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
