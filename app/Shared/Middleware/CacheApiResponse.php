<?php

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds HTTP caching headers to GET responses so clients can revalidate
 * cheaply instead of re-downloading unchanged data.
 *
 * - Default (max-age 0): `private, no-cache` — clients must revalidate every
 *   time, so live dashboard refetches never read a stale browser cache.
 * - A positive max-age allows private caching for that many seconds.
 * - Responses carry user-specific data, so they are never `public`.
 * - Generates an ETag from the response body for conditional revalidation.
 * - Returns 304 Not Modified when the client sends a matching If-None-Match.
 */
class CacheApiResponse
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $maxAge = null): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->isSuccessful()) {
            $seconds = (int) ($maxAge ?? config('api-cache.max_age', 60));

            $response->headers->set('Cache-Control', $seconds > 0
                ? "private, max-age={$seconds}, must-revalidate"
                : 'private, no-cache');

            $etag = '"'.md5($response->getContent()).'"';
            $response->headers->set('ETag', $etag);
            $response->headers->set('X-Cache-Max-Age', (string) $seconds);

            if ($request->headers->has('If-None-Match')
                && $request->header('If-None-Match') === $etag) {
                return response()->noContent(304, ['ETag' => $etag]);
            }
        }

        return $response;
    }
}
