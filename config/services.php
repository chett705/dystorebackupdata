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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'khqr' => [
        'gateway_url' => env('KHQR_GATEWAY_URL', 'https://khqr.cc/api/payment/request'),
        'profile_id' => env('KHQR_PROFILE_ID'),
        'secret_key' => env('KHQR_SECRET_KEY'),
        'default_amount' => env('KHQR_DEFAULT_AMOUNT', '0.01'),
        'default_remark' => env('KHQR_DEFAULT_REMARK', 'Wallet top up'),
    ],

    'supplier' => [
        'endpoint' => env('MLBB_SUPPLIER_ENDPOINT'),
        'api_key' => env('MLBB_SUPPLIER_API_KEY'),
        'timeout' => env('MLBB_SUPPLIER_TIMEOUT', 20),
    ],

    'game_lookup' => [
        'endpoint' => env('GAME_LOOKUP_ENDPOINT'),
        'api_key' => env('GAME_LOOKUP_API_KEY'),
        'timeout' => env('GAME_LOOKUP_TIMEOUT', 20),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

];
