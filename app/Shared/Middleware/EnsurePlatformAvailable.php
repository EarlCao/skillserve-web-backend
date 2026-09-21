<?php

namespace App\Shared\Middleware;

use App\Modules\Settings\Services\SettingsService;
use App\Shared\Services\ApiResponder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * System Settings → System → "Maintenance mode" closes the mobile API
 * (`/api/client/v1/*`) with a 503 the app recognises by `meta.maintenance`.
 * The admin web keeps working, so administrators can switch it off again, and
 * `GET /api/client/v1/platform` stays open so the app can tell when it is over.
 */
class EnsurePlatformAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(SettingsService::class)->value('system', 'maintenance_mode')) {
            return ApiResponder::error(
                'SkillServe is down for maintenance. Please try again in a little while.',
                503,
                meta: ['maintenance' => true],
                headers: ['Retry-After' => '300'],
            );
        }

        return $next($request);
    }
}
