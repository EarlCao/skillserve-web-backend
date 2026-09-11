<?php

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appends standard rate-limit headers to every API response so mobile
 * and web clients can throttle proactively instead of hitting 429s.
 *
 * Headers added:
 *   X-RateLimit-Limit     – max requests in the current window
 *   X-RateLimit-Remaining – requests left in the current window
 *   X-RateLimit-Reset     – UTC epoch seconds when the window resets
 */
class AddRateLimitHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $limiter = RateLimiter::limiter('api');
        $key = $limiter ? $limiter->resolveRequest($request) : $request->ip();

        if ($key && RateLimiter::maximum($limiter) !== null) {
            $max = RateLimiter::maximum($limiter);
            $remaining = RateLimiter::remaining($limiter, $key);
            $resetAt = RateLimiter::availableIn($limiter, $key);

            $response->headers->set('X-RateLimit-Limit', (string) $max);
            $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
            $response->headers->set('X-RateLimit-Reset', (string) (time() + $resetAt));
        }

        return $response;
    }
}
