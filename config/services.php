<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'telegram-bot-api' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_OAUTH_TESTING_MODE', 'false') === 'true'
            ? env('GOOGLE_CLIENT_ID_TESTING', env('GOOGLE_CLIENT_ID'))
            : env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_TESTING_MODE', 'false') === 'true'
            ? env('GOOGLE_CLIENT_SECRET_TESTING', env('GOOGLE_CLIENT_SECRET'))
            : env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_CALLBACK_URL'),
    ],

    'click' => [
        'merchant_id' => env('CLICK_MERCHANT_ID'),
        'dev_merchant_id' => env('DEV_CLICK_MERCHANT_ID'),
        'service_id' => env('CLICK_SERVICE_ID'),
        'dev_service_id' => env('DEV_CLICK_SERVICE_ID'),
        'secret_key' => env('CLICK_SECRET_KEY'),
        'merchant_user_id' => env('CLICK_MERCHANT_USER_ID'),
        'return_url' => env('CLICK_RETURN_URL'),
        'cancel_url' => env('CLICK_CANCEL_URL'),
    ],

    'payme' => [
        'merchant_id' => env('PAYME_MERCHANT_ID'),
        'dev_merchant_id' => env('DEV_PAYME_MERCHANT_ID'),
    ],

    'default_payment_provider' => env('DEFAULT_PAYMENT_PROVIDER', 'payme'),

    'onesignal' => [
        'app_id' => env('ONESIGNAL_APP_ID'),
        'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),
        'user_auth_key' => env('ONESIGNAL_USER_AUTH_KEY'),
    ],

    'docai' => [
        'project_id' => env('GOOGLE_DOC_AI_PROJECT_ID'),
        'location' => env('GOOGLE_DOC_AI_LOCATION', 'us'),
        'processor_id' => env('GOOGLE_DOC_AI_PROCESSOR_ID'),
        'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS') ? base_path(env('GOOGLE_APPLICATION_CREDENTIALS')) : base_path('config/document_invoice_parser.json'),
    ],

    'transcription' => [
        'default_service' => env('TRANSCRIPTION_DEFAULT_SERVICE', 'yandex'),
        'services' => [
            'yandex' => [
                'class' => \App\Services\SpeechToText\YandexSpeechKitService::class,
                'enabled' => env('TRANSCRIPTION_YANDEX_ENABLED', true),
                'config' => [
                    'api_key' => env('YANDEX_SPEECHKIT_STT_API_KEY'),
                    'folder_id' => env('YANDEX_SPEECHKIT_STT_FOLDER_ID'),
                    'auth_type' => env('YANDEX_SPEECHKIT_STT_AUTH_TYPE', 'iam'), // 'iam' for Bearer token, 'api-key' for Api-Key header, 'authorized-key' for JWT→IAM
                    'endpoint' => env('YANDEX_SPEECHKIT_STT_ENDPOINT', 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize'),
                    'authorized_key_path' => env('YANDEX_SPEECHKIT_STT_AUTHORIZED_KEY_PATH', storage_path('yandex_authorized_key.json')),
                ],
            ],
            'google_gemini' => [
                'class' => \App\Services\SpeechToText\GoogleGeminiTranscriptionService::class,
                'enabled' => env('TRANSCRIPTION_GEMINI_ENABLED', false),
                'config' => [
                    // Placeholder for future Gemini config
                ],
            ],
            'openai_voice' => [
                'class' => \App\Services\SpeechToText\OpenAIVoiceTranscriptionService::class,
                'enabled' => env('TRANSCRIPTION_OPENAI_ENABLED', false),
                'config' => [
                    // Placeholder for future OpenAI Voice config
                ],
            ],
        ],
        'fallback_chain' => ['yandex', 'google_gemini', 'openai_voice'],
    ],

    'text_to_speech' => [
        'default_service' => env('TTS_DEFAULT_SERVICE', 'yandex'),
        'services' => [
            'yandex' => [
                'class' => \App\Services\TextToSpeech\YandexTtsService::class,
                'enabled' => env('TTS_YANDEX_ENABLED', true),
                'config' => [
                    'api_key' => env('YANDEX_SPEECHKIT_TTS_API_KEY'),
                    'folder_id' => env('YANDEX_SPEECHKIT_TTS_FOLDER_ID'),
                    'auth_type' => env('YANDEX_SPEECHKIT_TTS_AUTH_TYPE', 'iam'), // 'iam' for Bearer token, 'api-key' for Api-Key header
                    'tts_endpoint' => env('YANDEX_SPEECHKIT_TTS_ENDPOINT', 'https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize'),
                    'tts_voice' => env('YANDEX_SPEECHKIT_TTS_VOICE', 'alena'),
                    'tts_lang' => env('YANDEX_SPEECHKIT_TTS_LANG', 'uz-UZ'),
                    'tts_format' => env('YANDEX_SPEECHKIT_TTS_FORMAT', 'oggopus'),
                    'tts_sample_rate' => env('YANDEX_SPEECHKIT_TTS_SAMPLE_RATE', '48000'),
                ],
            ],
        ],
    ],

    'expense_parser' => [
        'default_service' => env('EXPENSE_PARSER_DEFAULT_SERVICE', 'anthropic_claude'),
        'services' => [
            'openai_gpt' => [
                'class' => \App\Services\Expense\Parser\OpenAIGPTExpenseParserService::class,
                'enabled' => env('EXPENSE_PARSER_OPENAI_ENABLED', true),
                'config' => [
                    'model' => env('OPENAI_PARSER_MODEL', 'gpt-4o'),
                    'temperature' => env('OPENAI_PARSER_TEMPERATURE', 0),
                    'max_tokens' => env('OPENAI_PARSER_MAX_TOKENS', 2000),
                ],
            ],
            'google_gemini' => [
                'class' => \App\Services\Expense\Parser\GoogleGeminiExpenseParserService::class,
                'enabled' => env('EXPENSE_PARSER_GEMINI_ENABLED', false),
                'config' => [
                    'api_key' => env('GEMINI_API_KEY'),
                    'model' => env('GEMINI_PARSER_MODEL', 'gemini-2.0-flash'),
                ],
            ],
            'anthropic_claude' => [
                'class' => \App\Services\Expense\Parser\AnthropicClaudeExpenseParserService::class,
                'enabled' => env('EXPENSE_PARSER_CLAUDE_ENABLED', false),
                'config' => [
                    'api_key' => env('CLAUDE_API_KEY'),
                    'model' => env('CLAUDE_PARSER_MODEL', 'claude-sonnet-4-6'),
                    'max_tokens' => env('CLAUDE_PARSER_MAX_TOKENS', 2048),
                    'temperature' => env('CLAUDE_PARSER_TEMPERATURE', 0.0),
                    'version' => env('CLAUDE_API_VERSION', '2023-06-01'),
                    'base_url' => env('CLAUDE_API_BASE_URL', 'https://api.anthropic.com'),
                    'timeout' => env('CLAUDE_PARSER_TIMEOUT', 30),
                ],
            ],
        ],
        'fallback_chain' => ['anthropic_claude', 'openai_gpt', 'google_gemini'],
    ],

    'ai_support' => [
        'api_key' => env('CLAUDE_API_KEY'),
        'model' => env('AI_SUPPORT_MODEL', 'claude-haiku-4-5'),
        'max_tokens' => env('AI_SUPPORT_MAX_TOKENS', 1024),
        'version' => env('CLAUDE_API_VERSION', '2023-06-01'),
        'base_url' => env('CLAUDE_API_BASE_URL', 'https://api.anthropic.com'),
        'timeout' => env('AI_SUPPORT_TIMEOUT', 30),
        // Every current Claude model accepts image blocks, so this stays on.
        // Flip it off if AI_SUPPORT_MODEL is ever pointed at a text-only
        // model: chat screenshots then degrade to a text-only answer
        // (data.image_used=false) instead of a Messages API 400.
        'supports_vision' => env('AI_SUPPORT_MODEL_SUPPORTS_VISION', true),
    ],

    'freepbx' => [
        'base_url' => env('FREEPBX_BASE_URL', 'http://89.126.210.244'),
        'timeout' => env('FREEPBX_TIMEOUT', 30),
    ],

    // Telegram OAuth 2.0 / OpenID Connect login (the BotFather "Login Widget"
    // with Client ID + Secret). Distinct from the legacy hash-based widget.
    // Backend is the single confidential client — the secret never leaves here.
    'telegram_oidc' => [
        'client_id' => env('TELEGRAM_OIDC_CLIENT_ID'),
        'client_secret' => env('TELEGRAM_OIDC_CLIENT_SECRET'),
        // Server-side callback for the web/landing redirect flow. Must match a
        // Redirect URI registered in BotFather exactly.
        'redirect_uri' => env('TELEGRAM_OIDC_REDIRECT_URI', env('APP_URL').'/api/web/auth/telegram/oidc/callback'),
        // Where the backend sends the user after a successful web/landing login
        // (one-time token appended). Mobile uses the native exchange endpoint.
        'web_success_url' => env('TELEGRAM_OIDC_WEB_SUCCESS_URL', 'https://web.pdaftar.uz/auth/telegram/callback'),
        'authorize_url' => env('TELEGRAM_OIDC_AUTHORIZE_URL', 'https://oauth.telegram.org/auth'),
        'token_url' => env('TELEGRAM_OIDC_TOKEN_URL', 'https://oauth.telegram.org/token'),
        'jwks_url' => env('TELEGRAM_OIDC_JWKS_URL', 'https://oauth.telegram.org/.well-known/jwks.json'),
        'issuer' => env('TELEGRAM_OIDC_ISSUER', 'https://oauth.telegram.org'),
        'scopes' => env('TELEGRAM_OIDC_SCOPES', 'openid profile phone'),
    ],

];
