<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, Stripe and more. This file provides the de facto
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

    // 🚀 កូដពេញលេញសម្រាប់គម្រោង DyzzStore (PayWay, KHQR, Game Lookup, Supplier, Telegram)
    'payment_method' => env('PAYMENT_METHOD', 'payway'),

    'payway' => [
        'checkout_url' => env('PAYWAY_CHECKOUT_URL', 'https://link.payway.com.kh/ABAPAY9s540501U'),
    ],

    'khqr' => [
        'gateway_url' => env('KHQR_GATEWAY_URL'),
        'profile_id' => env('KHQR_PROFILE_ID'),
        'secret_key' => env('KHQR_SECRET_KEY'),
    ],

    'game_lookup' => [
        'endpoint' => env('GAME_LOOKUP_ENDPOINT'),
        'api_key' => env('GAME_LOOKUP_API_KEY'),
        'timeout' => (int) env('GAME_LOOKUP_TIMEOUT', 20),
    ],

    'supplier' => [
        'endpoint' => env('MLBB_SUPPLIER_ENDPOINT'),
        'api_key' => env('MLBB_SUPPLIER_API_KEY'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'flash_topup' => [
        'api_id' => env('FLASH_TOPUP_API_ID'),
        'secret_key' => env('FLASH_TOPUP_SECRET_KEY'),
    ],

];
