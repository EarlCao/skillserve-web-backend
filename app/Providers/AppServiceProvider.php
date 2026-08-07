<?php

namespace App\Providers;

use App\Modules\Authentication\Events\AdministratorLoggedIn;
use App\Modules\Authentication\Events\AdministratorLoggedOut;
use App\Modules\Authentication\Events\PasswordChanged;
use App\Modules\Authentication\Listeners\LogAuthenticationActivity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limiter used by the shared "api" middleware group.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Throttle failed login attempts per IP address.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute((int) env('LOGIN_RATE_LIMIT', 5))->by($request->ip());
        });

        // Super-admins bypass every authorization gate (authorization is
        // still enforced for every other role through policies/gates).
        Gate::before(function ($user, string $ability) {
            return $user?->hasRole('super-admin') ? true : null;
        });

        // The module lives outside app/Events + app/Listeners, so register
        // the event→listener wiring explicitly instead of relying on
        // auto-discovery.
        Event::listen(AdministratorLoggedIn::class, LogAuthenticationActivity::class);
        Event::listen(AdministratorLoggedOut::class, LogAuthenticationActivity::class);
        Event::listen(PasswordChanged::class, LogAuthenticationActivity::class);
    }
}
