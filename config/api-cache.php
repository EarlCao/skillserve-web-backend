<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Response Cache Lifetime (seconds)
    |--------------------------------------------------------------------------
    |
    | Default max-age for Cache-Control headers on successful GET responses.
    | Mobile and web clients will keep responses in cache for this duration
    | before re-fetching from the server. Override per-route by passing a
    | max-age argument to the middleware: middleware('cache:300').
    |
    */

    'max_age' => (int) env('API_CACHE_MAX_AGE', 60),

];
