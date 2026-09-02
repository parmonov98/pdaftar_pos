<?php

return [
    'otp_service' => env('OTP_SERVICE', ''),
    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'telegram_chat_id' => env('TELEGRAM_CHAT_ID', ''),
    'ERROR_REPORT_TELEGRAM_CHAT_ID' => env('ERROR_TELEGRAM_CHAT_ID', ''),
    'eskiz_login' => env('ESKIZ_LOGIN', ''),
    'eskiz_password' => env('ESKIZ_PASSWORD', ''),
    // Second Eskiz account, tried when the primary refuses a text it has not
    // had moderated. Optional — leave unset and the send path behaves exactly
    // as it did with one account. Provisioned by `php artisan eskiz:set-fallback`.
    'eskiz_fallback_login' => env('ESKIZ_FALLBACK_LOGIN', ''),
    'eskiz_fallback_password' => env('ESKIZ_FALLBACK_PASSWORD', ''),
    'android_url' => env('ANDROID_URL', 'pdaftar.uz/android'),
    'ios_url' => env('IOS_URL', 'pdaftar.uz/ios'),
    'voice_otp_fallback_enabled' => env('VOICE_OTP_FALLBACK_ENABLED', false),
];
