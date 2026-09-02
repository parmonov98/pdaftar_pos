<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MobSMS gateway (per-owner Android SIM gateway, reminders only)
    |--------------------------------------------------------------------------
    */
    'mobsms' => [
        'base_url' => env('MOBSMS_BASE_URL', 'https://api.mobsms.cloud'),
        // How long to cache an owner's device list (phone→device_id resolution).
        'devices_cache_ttl' => (int) env('MOBSMS_DEVICES_CACHE_TTL', 600),
        'http_timeout' => (int) env('MOBSMS_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('MOBSMS_CONNECT_TIMEOUT', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-part MobSMS platform fee by the owner's active tariff (UZS)
    |--------------------------------------------------------------------------
    | Charged from the owner's wallet_balance when a shop sends reminders via
    | MobSMS. Premium tiers (O'rta/Katta) are bundled at 0. Resolved per account
    | by MobSmsRateResolver and stored on user_sms_accounts.sms_rate.
    */
    'mobsms_rates' => [
        'by_name' => [
            'Bepul' => 150,
            'Kichik' => 100,
            "O'rta" => 0,
            'Katta' => 0,
            // Top tier: bundled like Katta — its 160 UZS/SMS lives in the
            // sms_tariffs packages, not in this MobSMS platform fee.
            'Distribyutor' => 0,
        ],
        'free' => 150,      // any tariff flagged is_free
        'default' => 0,     // other/legacy paid tariffs (Starter/Plus/Pro/Special…)
        'no_tariff' => 150, // no active subscription → treat as free tier
    ],
];
