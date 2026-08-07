<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Administrators\Events\AdministratorCreated;
use App\Modules\Administrators\Events\AdministratorStatusChanged;
use App\Modules\Administrators\Events\AdministratorUpdated;
use App\Modules\Administrators\Events\RoleCreated;
use App\Modules\Administrators\Events\RoleDeleted;
use App\Modules\Administrators\Events\RolePermissionsSynced;
use App\Modules\Administrators\Events\RoleUpdated;
use App\Modules\Administrators\Listeners\LogAdministratorActivity;
use App\Modules\Administrators\Policies\AdministratorPolicy;
use App\Modules\Administrators\Policies\PermissionPolicy;
use App\Modules\Administrators\Policies\RolePolicy;
use App\Modules\Authentication\Events\AdministratorLoggedIn;
use App\Modules\Authentication\Events\AdministratorLoggedOut;
use App\Modules\Authentication\Events\PasswordChanged;
use App\Modules\Authentication\Listeners\LogAuthenticationActivity;
use App\Modules\Users\Events\UserActivated;
use App\Modules\Users\Events\UserBanned;
use App\Modules\Users\Events\UserDeleted;
use App\Modules\Users\Events\UserSuspended;
use App\Modules\Users\Events\UserUpdated;
use App\Modules\Users\Listeners\LogUserActivity;
use App\Modules\Users\Policies\UserManagementPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

        // The modules live outside app/Events + app/Listeners, so register
        // the event→listener wiring explicitly instead of relying on
        // auto-discovery.
        Event::listen(AdministratorLoggedIn::class, LogAuthenticationActivity::class);
        Event::listen(AdministratorLoggedOut::class, LogAuthenticationActivity::class);
        Event::listen(PasswordChanged::class, LogAuthenticationActivity::class);

        // Administrator Management module events.
        Event::listen(AdministratorCreated::class, LogAdministratorActivity::class);
        Event::listen(AdministratorUpdated::class, LogAdministratorActivity::class);
        Event::listen(AdministratorStatusChanged::class, LogAdministratorActivity::class);
        Event::listen(RoleCreated::class, LogAdministratorActivity::class);
        Event::listen(RoleUpdated::class, LogAdministratorActivity::class);
        Event::listen(RoleDeleted::class, LogAdministratorActivity::class);
        Event::listen(RolePermissionsSynced::class, LogAdministratorActivity::class);

        // User Management module events.
        Event::listen(UserUpdated::class, LogUserActivity::class);
        Event::listen(UserSuspended::class, LogUserActivity::class);
        Event::listen(UserActivated::class, LogUserActivity::class);
        Event::listen(UserBanned::class, LogUserActivity::class);
        Event::listen(UserDeleted::class, LogUserActivity::class);

        // Administrator Management module policies.
        Gate::policy(User::class, AdministratorPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);

        // User Management — the User model policy slot belongs to the
        // Administrators module, so this module exposes a single named ability
        // backed by its own policy class.
        Gate::define('manage users', [UserManagementPolicy::class, 'manage']);
    }
}
