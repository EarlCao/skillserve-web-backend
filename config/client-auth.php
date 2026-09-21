<?php

return [
    'access_ability' => 'client:auth',
    // A second, narrow token the app hands to its background task so it can
    // check for new notifications while the app is closed. It reads the
    // notification feed and nothing else, and it never refreshes, so it
    // cannot collide with the app's rotating refresh token.
    'background_ability' => 'client:notifications',
    // Minutes the background token lives. Signing out, changing the password
    // or losing the account ends it earlier (every token is revoked).
    'background_token_expiration' => (int) env('CLIENT_BACKGROUND_TOKEN_EXPIRATION', 525600),
    'access_token_expiration' => (int) env('CLIENT_ACCESS_TOKEN_EXPIRATION', 60),
    // Where profile photos live. `public` serves them through the storage
    // symlink; in production storage/app is a Render persistent disk, so they
    // survive a redeploy (DEPLOYMENT.md → "Uploaded files").
    'profile_photo_disk' => env('CLIENT_PROFILE_PHOTO_DISK', 'public'),
    'password_reset_url' => env('CLIENT_PASSWORD_RESET_URL', env('APP_URL', 'http://localhost:8000').'/client/reset-password'),
    // Minutes a refresh token lives, counted from its last use: every refresh
    // issues a new one, so an app that keeps being opened stays signed in
    // indefinitely. A year means only a user who never opens the app in that
    // time signs in again — mobile sessions end when the user signs out, not on
    // a timer. The server can still end one early: password change, suspension,
    // deletion, or reuse of a rotated token (which revokes the whole family).
    'refresh_token_expiration' => (int) env('CLIENT_REFRESH_TOKEN_EXPIRATION', 525600),
];
