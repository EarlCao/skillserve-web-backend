<?php

namespace App\Modules\ClientAuthentication\Middleware;

use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user || ! $user->isMobileAccount() || ! $token?->can(config('client-auth.access_ability'))) {
            throw new ApiException('This token is not valid for client access.', 403);
        }

        if (! $user->isActive()) {
            throw new ApiException('Your account is not active.', 403);
        }

        return $next($request);
    }
}
