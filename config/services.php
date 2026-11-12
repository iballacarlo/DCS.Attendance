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

    'hardware' => [
        'api_key' => env('HARDWARE_API_KEY'),
        'enrollment_expiry_minutes' => max(1, min(60, (int) env('HARDWARE_ENROLLMENT_EXPIRY_MINUTES', 15))),
    ],

    'google_mail' => [
        'enabled' => env('GOOGLE_MAIL_ENABLED', env('APP_ENV', 'production') === 'production'),
        'webhook_url' => env('GOOGLE_MAIL_WEBHOOK_URL'),
        'webhook_secret' => env('GOOGLE_MAIL_WEBHOOK_SECRET'),
        'logo_url' => env('GOOGLE_MAIL_LOGO_URL'),
        'dcs_logo_url' => env('GOOGLE_MAIL_DCS_LOGO_URL'),
    ],

];
