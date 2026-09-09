<?php

return [
    'access_ability' => 'client:auth',
    'access_token_expiration' => (int) env('CLIENT_ACCESS_TOKEN_EXPIRATION', 60),
    'password_reset_url' => env('CLIENT_PASSWORD_RESET_URL', env('APP_URL', 'http://localhost:8000').'/client/reset-password'),
    'refresh_token_expiration' => (int) env('CLIENT_REFRESH_TOKEN_EXPIRATION', 20160),
];
