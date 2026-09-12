<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The SPA (Vite, http://localhost:5173) calls the API directly from the
    | browser, so the frontend origin must be allowed. Credentials are
    | required because the shared axios client sends Authorization tokens.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL', '*'),
        ...array_map('trim', explode(',', env('FRONTEND_URLS', ''))),
        // Always allow localhost for development
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
    ]),

    'allowed_origins_patterns' => [
        '/^https:\/\/.*\.vercel\.app$/',
        '/^https:\/\/.*\.onrender\.com$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
