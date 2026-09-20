<?php

return [
    'access_ability' => 'client:auth',
    'access_token_expiration' => (int) env('CLIENT_ACCESS_TOKEN_EXPIRATION', 60),
    // Where profile photos live. `public` serves them through the storage
    // symlink; point this at an S3 disk in production so they survive a
    // redeploy of an ephemeral container.
    'profile_photo_disk' => env('CLIENT_PROFILE_PHOTO_DISK', 'public'),
    'password_reset_url' => env('CLIENT_PASSWORD_RESET_URL', env('APP_URL', 'http://localhost:8000').'/client/reset-password'),
    'refresh_token_expiration' => (int) env('CLIENT_REFRESH_TOKEN_EXPIRATION', 20160),
];
