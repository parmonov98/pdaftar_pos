<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    'notification_disabled' => env('NOTIFICATION_DISABLED', false),

    'sms_disabled' => env('SMS_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | SMS Test Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, SMS will only be sent to shops in the allowed list.
    | This is useful for testing with production data without sending
    | SMS to real customers.
    |
    */
    'sms_test_mode' => env('SMS_TEST_MODE', false),

    'sms_test_shop_ids' => env('SMS_TEST_SHOP_IDS', ''), // Comma-separated shop IDs

    'sms_test_user_ids' => env('SMS_TEST_USER_IDS', ''), // Comma-separated user IDs

    /*
    |--------------------------------------------------------------------------
    | Job Processing Max Age
    |--------------------------------------------------------------------------
    |
    | These values determine how old jobs can be before they are no longer
    | processed. Jobs older than these limits are considered stale and will
    | be marked as failed/expired to prevent processing irrelevant data.
    |
    */
    'sms_job_max_age_days' => env('SMS_JOB_MAX_AGE_DAYS', 2),
    'sms_action_max_age_days' => env('SMS_ACTION_MAX_AGE_DAYS', 2),
    'voice_call_max_age_days' => env('VOICE_CALL_MAX_AGE_DAYS', 2),

    /*
   |--------------------------------------------------------------------------
   | Application version
   |--------------------------------------------------------------------------
   |
   | When you want knows application version .
   |
   */

    'version' => env('APP_VERSION', '1.0'),

    /*
   |--------------------------------------------------------------------------
   | Application platform versions
   |--------------------------------------------------------------------------
   |
   | When you want to know specific platform versions .
   |
   */

    'android_version' => env('ANDROID_VERSION', '1.3.3'),
    'ios_version' => env('IOS_VERSION', '1.3.3'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    // The auth-gated web app (React SPA). Used by flows that require a logged-in
    // session, e.g. the payment/top-up return URLs (PaymentLinkService). On prod
    // this is FRONTEND_URL=https://web.pdaftar.uz.
    'frontend_url' => env('FRONTEND_URL', 'https://pdaftar.uz'),

    // The public landing site (no login). Used by links whose token/uuid IS the
    // credential and must open a page anyone can view: client history (/c/{token}),
    // shopping-list share (/q/{uuid}), referral/app links (/r/{code}, /app).
    // Defaults to pdaftar.uz so these keep working even if PUBLIC_URL is unset.
    'public_url' => env('PUBLIC_URL', 'https://pdaftar.uz'),

    'support_bot_webhook_url' => env('SUPPORT_BOT_WEBHOOK_URL'),

    'admin_username' => env('ADMIN_USERNAME', ''),
    'admin_password' => env('ADMIN_PASSWORD', ''),

    'shop_map_username' => env('SHOP_MAP_USERNAME', 'admin'),
    'shop_map_password' => env('SHOP_MAP_PASSWORD', 'password'),

    'asset_url' => env('ASSET_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'Asia/Tashkent',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => 'uz',

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeds. For example, this will be used to get
    | localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => 'file',
        // 'store'  => 'redis',
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    /*
     * Deliberately just the framework defaults.
     *
     * pDaftar's list here boots Filament panels, Horizon and Telescope
     * dashboards, Firebase, OneSignal and PDF rendering — none of which a till
     * needs, several of which are not even installed in this app, and any one of
     * which failing would take the POS down with it. The POS's own provider is
     * registered in bootstrap/providers.php; installed packages (Sanctum,
     * media-library, permission) arrive through Composer auto-discovery.
     */
    'providers' => ServiceProvider::defaultProviders()->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */

    'aliases' => Facade::defaultAliases()->merge([
        // 'Example' => App\Facades\Example::class,
    ])->toArray(),

];
