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

    // Resend's email API (MAIL_MAILER=resend-api), an alternative mailer.
    // MAIL_FROM_ADDRESS must be on a domain verified in Resend.
    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'timeout' => (int) env('RESEND_API_TIMEOUT', 15),
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
    // Twilio Verify for the mobile codes (OTP_DRIVER=twilio). Its email
    // channel sends through the SendGrid account linked to the Verify service.
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
        // Optional: a different SendGrid template for password-reset codes.
        'verify_reset_template_id' => env('TWILIO_VERIFY_RESET_TEMPLATE_ID'),
        'timeout' => (int) env('TWILIO_TIMEOUT', 15),
    ],

    // Gmail API (MAIL_MAILER=gmail-api): the no-domain alternative, sent as
    // the authorised Gmail account. The refresh token carries the gmail.send
    // scope.
    'gmail' => [
        'client_id' => env('GMAIL_CLIENT_ID'),
        'client_secret' => env('GMAIL_CLIENT_SECRET'),
        'refresh_token' => env('GMAIL_REFRESH_TOKEN'),
        'timeout' => (int) env('GMAIL_API_TIMEOUT', 15),
    ],

    // Mailjet's Send API (MAIL_MAILER=mailjet-api). Built and tested, but the
    // new account was blocked on 2026-10-06.
    'mailjet' => [
        'key' => env('MAILJET_API_KEY'),
        'secret' => env('MAILJET_SECRET_KEY'),
        'timeout' => (int) env('MAILJET_API_TIMEOUT', 15),
    ],

    // SendGrid's HTTP mail API (MAIL_MAILER=sendgrid-api) for every other email.
    'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
        'timeout' => (int) env('SENDGRID_API_TIMEOUT', 15),
    ],

    // Brevo's transactional API (MAIL_MAILER=brevo-api): the production
    // mailer for the 6-digit codes and every other email.
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
