<?php

namespace App\Modules\ClientMarketplace\Middleware;

use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user || ! $user->isClientAccount() || ! $token?->can(config('client-auth.access_ability'))) {
            throw new ApiException('This token is not valid for client access.', 403);
        }

        if (! $user->isActive()) {
            throw new ApiException('This endpoint is available to active customer accounts only.', 403);
        }

        if (! $user->hasVerifiedEmail()) {
            throw new ApiException('Please verify your email address before using client marketplace features.', 403);
        }

        return $next($request);
    }
}
