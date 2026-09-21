<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Only the admin web's own addresses. A wildcard such as *.vercel.app or
    // *.onrender.com would trust any site anyone deploys there. Add a new
    // deployment through FRONTEND_URL / FRONTEND_URLS (comma-separated).
    'allowed_origins' => array_values(array_unique(array_filter(array_map(
        fn (string $origin): string => rtrim(trim($origin), '/'),
        [
            'https://skillserve-admin-side.vercel.app',
            'https://skillserve-web-admin.vercel.app',
            'https://skillserve-frontend.onrender.com',
            'http://localhost:5173',
            'http://localhost:3000',
            'http://127.0.0.1:5173',
            (string) env('FRONTEND_URL', ''),
            ...explode(',', (string) env('FRONTEND_URLS', '')),
        ],
    )))),

    'allowed_origins_patterns' => array_values(array_filter([
        // Local only: `flutter run -d edge/chrome` serves on a random localhost port.
        env('APP_ENV') === 'local' ? '/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/' : null,
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
