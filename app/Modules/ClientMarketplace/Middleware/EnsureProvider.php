<?php

namespace App\Modules\ClientMarketplace\Middleware;

use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts routes to active, email-verified provider accounts signed in
 * through the mobile client API.
 */
class EnsureProvider
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user || ! $user->isMobileProviderAccount() || ! $token?->can(config('client-auth.access_ability'))) {
            throw new ApiException('This endpoint is available to provider accounts only.', 403);
        }

        if (! $user->isActive()) {
            throw new ApiException('This endpoint is available to active provider accounts only.', 403);
        }

        if (! $user->hasVerifiedEmail()) {
            throw new ApiException('Please verify your email address before managing services.', 403);
        }

        return $next($request);
    }
}
