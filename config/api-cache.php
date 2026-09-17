<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Response Cache Lifetime (seconds)
    |--------------------------------------------------------------------------
    |
    | Default max-age for Cache-Control headers on successful GET responses.
    | 0 (default) sends `private, no-cache`: clients revalidate with the ETag
    | on every request, which keeps the realtime admin dashboard from reading
    | stale browser-cached data. Override per-route by passing a max-age
    | argument to the middleware: middleware('cache.api:300').
    |
    */

    'max_age' => (int) env('API_CACHE_MAX_AGE', 0),

];
