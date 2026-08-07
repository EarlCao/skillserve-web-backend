<?php

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reusable middleware guarding the API documentation endpoints.
 *
 * Swagger UI + the raw spec are served in every environment except
 * production, where they are hidden unless SWAGGER_UI_ENABLED=true.
 *
 * Registered on the l5-swagger "api" and "docs" routes in
 * config/l5-swagger.php.
 */
class EnsureSwaggerUiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = app()->environment('production')
            ? (bool) env('SWAGGER_UI_ENABLED', false)
            : true;

        abort_unless($enabled, 404);

        return $next($request);
    }
}
