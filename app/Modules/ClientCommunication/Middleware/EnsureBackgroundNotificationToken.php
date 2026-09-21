<?php

namespace App\Modules\ClientCommunication\Middleware;

use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits the background notification token (and nothing broader is needed):
 * an active, verified mobile account presenting a token with the
 * `client:notifications` ability.
 */
class EnsureBackgroundNotificationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user || ! $user->isMobileAccount() || ! $token?->can(config('client-auth.background_ability'))) {
            throw new ApiException('This token is not valid for background notifications.', 403);
        }

        if (! $user->isActive() || ! $user->hasVerifiedEmail()) {
            throw new ApiException('This endpoint is available to active accounts only.', 403);
        }

        return $next($request);
    }
}
