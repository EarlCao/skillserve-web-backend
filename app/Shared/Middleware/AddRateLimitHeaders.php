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
 *
 * Note: RateLimiter::limiter() returns the registered *closure* resolver,
 * not an object — the limiter state (hits/timers) lives in the cache under
 * a hashed key, exactly like Illuminate's ThrottleRequests middleware
 * computes it (see resolveRequestSignature / handleRequestUsingNamedLimiter).
 * Headers already stamped by the group's throttle middleware are never
 * overridden, and decoration is best-effort: it must never turn a valid
 * response into a 500.
 */
class AddRateLimitHeaders
{
    /**
     * Max requests per minute for the shared "api" limiter.
     * Keep in sync with RateLimiter::for('api', ...) in AppServiceProvider.
     */
    private const API_MAX_ATTEMPTS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        return $this->addHeaders($request, $next($request));
    }

    private function addHeaders(Request $request, Response $response): Response
    {
        try {
            // throttle:api (and throttle:login) already add these headers on
            // the responses they guard — leave theirs untouched.
            if ($response->headers->has('X-RateLimit-Remaining')) {
                return $response;
            }

            // Same signature core ThrottleRequests uses: authenticated user
            // id when available, otherwise domain|ip — then hashed.
            $key = sha1(
                $request->user()?->getAuthIdentifier()
                    ?? ($request->route()?->getDomain() ?? '').'|'.$request->ip()
            );

            $attempts = (int) RateLimiter::attempts($key);
            $remaining = max(0, self::API_MAX_ATTEMPTS - $attempts);
            $resetIn = RateLimiter::availableIn($key);

            $response->headers->set('X-RateLimit-Limit', (string) self::API_MAX_ATTEMPTS);
            $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
            $response->headers->set('X-RateLimit-Reset', (string) (time() + $resetIn));
        } catch (\Throwable) {
            // Header decoration is informational only — never break the
            // response because of it.
        }

        return $response;
    }
}
