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

    // Google Sign-In for the mobile app. The audience check uses the same
    // client ID the Flutter app was built with (google_sign_in serverClientId
    // / Android OAuth client ID).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    // Brevo transactional email (REST API transport). Read via config() at
    // runtime — never env() outside config files, which returns null once
    // `php artisan config:cache` has run (Render does this at boot).
    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
        'timeout' => (int) env('BREVO_API_TIMEOUT', 15),
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

];
