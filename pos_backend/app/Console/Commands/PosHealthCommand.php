<?php

declare(strict_types=1);

namespace Pos\Console\Commands;

use Illuminate\Console\Command;
use Pos\Services\PosIntegrityService;

/**
 * The same integrity checks the /health endpoint runs, from the shell.
 *
 * Made a command so it can gate a deploy: run it after pulling and before
 * putting traffic back, and a broken link to pDaftar's shared domain stops the
 * release instead of quietly costing a day of Kassa entries.
 *
 *   php artisan pos:health          # human output
 *   php artisan pos:health --json   # for a script
 *
 * Exit code 1 when anything fails, so `&&` chains do the right thing.
 */
class PosHealthCommand extends Command {
    protected $signature = 'pos:health {--json : Mashina o\'qishi uchun JSON}';

    protected $description = 'pDaftar bilan bog\'liqlikni tekshirish (domen, observerlar, baza, navbat)';

    public function handle(PosIntegrityService $integrity): int {
        $result = $integrity->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($result['checks'] as $check) {
            $this->line(sprintf(
                '  %s  <options=bold>%-16s</> %s',
                $check['ok'] ? '<fg=green>OK  </>' : '<fg=red>XATO</>',
                $check['name'],
                $check['detail'],
            ));
        }

        $this->newLine();

        if ($result['ok']) {
            $this->info('Hammasi joyida — POS pDaftar bilan to\'g\'ri bog\'langan.');

            return self::SUCCESS;
        }

        $this->error('Bog\'liqlikda muammo bor. Yuqoridagi XATO qatorlarini tuzatmaguncha POS\'ni ishga tushirmang.');

        return self::FAILURE;
    }
}
