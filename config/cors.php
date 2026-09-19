<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://skillserve-admin-side.vercel.app',
        'https://skillserve-web-admin.vercel.app',
        'https://skillserve-frontend.onrender.com',
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => array_values(array_filter([
        '/^https:\/\/.*\.vercel\.app$/',
        '/^https:\/\/.*\.onrender\.com$/',
        // Local only: `flutter run -d edge/chrome` serves on a random localhost port.
        env('APP_ENV') === 'local' ? '/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/' : null,
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
