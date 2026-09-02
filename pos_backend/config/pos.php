<?php

declare(strict_types=1);

return [
    /*
     * The Redis key prefix pDaftar's workers read from.
     *
     * StoreDebtUseCase queues ProcessDebtBalanceAndSms, which is what recomputes
     * a client's balance after a nasiya sale. Redis keys are namespaced by
     * prefix, and Laravel derives the default from APP_NAME — so if this app is
     * named differently it queues into a namespace pDaftar's horizon never
     * looks at. Nothing errors. Balances simply stop updating.
     *
     * Set this to what pDaftar computes (its APP_NAME slug + "_database_") and
     * /health will tell you the moment the two stop matching.
     */
    'expected_redis_prefix' => env('POS_EXPECTED_REDIS_PREFIX', ''),
];
