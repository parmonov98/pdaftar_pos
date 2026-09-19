<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Third-Party Services
|--------------------------------------------------------------------------
|
| This file was pDaftar's, copied whole: Click and Payme checkout, OneSignal
| push, Google Document AI, three speech-to-text providers, two text-to-speech
| providers, four expense parsers, an AI support desk, FreePBX and Telegram
| OIDC. Every one of them named a class under App\, which this application no
| longer autoloads — so the file was both dead weight and a set of fatal
| errors waiting for whichever config value someone read first.
|
| A till integrates with a printer and a barcode scanner, and both of those
| live in the browser. When the POS genuinely needs a third-party service,
| it gets added here deliberately.
|
*/

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
