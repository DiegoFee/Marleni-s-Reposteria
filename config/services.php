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

    'notifications' => [
        'enabled' => (bool) env('NOTIFICATIONS_ENABLED', false),
        'channel' => env('NOTIFICATION_CHANNEL', 'telegram'),
        'telegram' => [
            'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('TELEGRAM_CHAT_ID'),
            'connect_timeout' => (int) env('TELEGRAM_CONNECT_TIMEOUT', 5),
            'timeout' => (int) env('TELEGRAM_TIMEOUT', 10),
        ],
        'whatsapp' => [
            'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v20.0'),
            'recipient' => env('WHATSAPP_RECIPIENT'),
            'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
            'connect_timeout' => (int) env('WHATSAPP_CONNECT_TIMEOUT', 3),
            'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),
        ],
    ],

];
