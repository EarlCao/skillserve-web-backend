<?php

use App\Shared\Exceptions\ApiException;
use App\Shared\Middleware\AddRateLimitHeaders;
use App\Shared\Middleware\CacheApiResponse;
use App\Shared\Middleware\ForceJsonResponse;
use App\Shared\Services\ApiResponder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api',
        'middleware' => ['api', 'auth:sanctum'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi('api');

        $middleware->alias([
            'force.json' => ForceJsonResponse::class,
            'cache.api' => CacheApiResponse::class,
            'rate-limit.headers' => AddRateLimitHeaders::class,
            // Spatie permission middleware — used by every future module.
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Force JSON on every API request so errors always come back as the
        // standard envelope. appendToGroup keeps the framework's defaults
        // (throttle:api, SubstituteBindings, future Sanctum stateful API).
        $middleware->appendToGroup('api', ForceJsonResponse::class);
        $middleware->appendToGroup('api', CacheApiResponse::class);
        $middleware->appendToGroup('api', AddRateLimitHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Only API/JSON requests receive the standard envelope; everything
        // else falls through to Laravel's default handling.
        $isApiRequest = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (ApiException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponder::error($e->getMessage(), $e->status, errors: $e->errors, meta: $e->meta);
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponder::error('The given data was invalid.', 422, errors: $e->errors());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponder::error('Unauthenticated.', 401);
        });

        $exceptions->render(function (QueryException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponder::error('A database error occurred.', 500);
        });

        // Catch-all. Note: the framework converts ModelNotFoundException and
        // AuthorizationException into NotFoundHttpException /
        // AccessDeniedHttpException before callbacks run, so they surface here.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            if ($e instanceof NotFoundHttpException) {
                return ApiResponder::error('Resource not found.', 404);
            }

            if ($e instanceof AccessDeniedHttpException) {
                return ApiResponder::error('This action is unauthorized.', 403);
            }

            $isHttp = $e instanceof HttpExceptionInterface;

            return ApiResponder::error(
                $isHttp ? ($e->getMessage() ?: 'Request failed.') : 'Server Error.',
                $isHttp ? $e->getStatusCode() : 500,
            );
        });
    })->create();
