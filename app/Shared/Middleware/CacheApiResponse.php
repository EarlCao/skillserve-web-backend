<?php

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds HTTP caching headers to GET responses so mobile and web clients
 * can cache API data locally and reduce unnecessary network requests.
 *
 * - Sets Cache-Control with a configurable max-age.
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

            $response->headers->set('Cache-Control', "public, max-age={$seconds}, must-revalidate");

            $etag = '"' . md5($response->getContent()) . '"';
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
