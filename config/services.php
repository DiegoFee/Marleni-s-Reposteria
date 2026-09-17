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
        'enabled' => (bool) env('NOTIFICATION_ENABLED', false),
        'channel' => env('NOTIFICATION_CHANNEL', 'telegram'),
        'recipient' => env('NOTIFICATION_RECIPIENT'),
        'connect_timeout' => (int) env('NOTIFICATION_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('NOTIFICATION_TIMEOUT', 10),
        'telegram' => [
            'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        ],
        'whatsapp' => [
            'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v20.0'),
            'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        ],
    ],

];
