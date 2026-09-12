<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Administrators\Events\AdministratorCreated;
use App\Modules\Administrators\Events\AdministratorPasswordChanged;
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
use App\Modules\Analytics\Policies\AnalyticsPolicy;
use App\Modules\Audit\Policies\AuditLogPolicy;
use App\Modules\Authentication\Events\AdministratorLoggedIn;
use App\Modules\Authentication\Events\AdministratorLoggedOut;
use App\Modules\Authentication\Events\AdministratorLoginFailed;
use App\Modules\Authentication\Events\PasswordChanged;
use App\Modules\Authentication\Listeners\LogAuthenticationActivity;
use App\Modules\Bookings\Events\BookingCancelled;
use App\Modules\Bookings\Events\BookingDisputeManaged;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Listeners\LogBookingActivity;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Policies\BookingPolicy;
use App\Modules\ClientCommunication\Listeners\BroadcastClientNotification;
use App\Modules\ClientCommunication\Listeners\NotifyClientSupportTicket;
use App\Modules\Dashboard\Policies\DashboardPolicy;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Policies\AnnouncementPolicy;
use App\Modules\ProviderRecognition\Events\ProviderRecognitionChanged;
use App\Modules\ProviderRecognition\Listeners\LogProviderRecognitionActivity;
use App\Modules\ProviderRecognition\Policies\ProviderRecognitionPolicy;
use App\Modules\Providers\Events\ProviderActivated;
use App\Modules\Providers\Events\ProviderAdditionalInfoRequested;
use App\Modules\Providers\Events\ProviderSuspended;
use App\Modules\Providers\Events\ProviderVerificationApproved;
use App\Modules\Providers\Events\ProviderVerificationRejected;
use App\Modules\Providers\Events\ProviderVerificationRemoved;
use App\Modules\Providers\Listeners\LogProviderActivity;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Policies\ProviderPolicy;
use App\Modules\ReportsAndModeration\Events\ReportActionTaken;
use App\Modules\ReportsAndModeration\Events\ReportInvestigated;
use App\Modules\ReportsAndModeration\Events\ReportNoteAdded;
use App\Modules\ReportsAndModeration\Events\ReportRejected;
use App\Modules\ReportsAndModeration\Events\ReportResolved;
use App\Modules\ReportsAndModeration\Listeners\LogReportActivity;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\ReportsAndModeration\Policies\ReportPolicy;
use App\Modules\Reviews\Events\ReviewHidden;
use App\Modules\Reviews\Events\ReviewRemoved;
use App\Modules\Reviews\Events\ReviewRestored;
use App\Modules\Reviews\Listeners\LogReviewActivity;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Policies\ReviewPolicy;
use App\Modules\ServiceCategories\Events\ServiceCategoryCreated;
use App\Modules\ServiceCategories\Events\ServiceCategoryDeleted;
use App\Modules\ServiceCategories\Events\ServiceCategoryStatusChanged;
use App\Modules\ServiceCategories\Events\ServiceCategoryUpdated;
use App\Modules\ServiceCategories\Events\ServiceSubcategoryCreated;
use App\Modules\ServiceCategories\Events\ServiceSubcategoryDeleted;
use App\Modules\ServiceCategories\Events\ServiceSubcategoryUpdated;
use App\Modules\ServiceCategories\Listeners\LogServiceCategoryActivity;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Policies\ServiceCategoryPolicy;
use App\Modules\Services\Events\ServiceApproved;
use App\Modules\Services\Events\ServiceCreated;
use App\Modules\Services\Events\ServiceDeleted;
use App\Modules\Services\Events\ServiceFeatured;
use App\Modules\Services\Events\ServiceHidden;
use App\Modules\Services\Events\ServiceRejected;
use App\Modules\Services\Events\ServiceUpdated;
use App\Modules\Services\Listeners\LogServiceActivity;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Policies\ServicePolicy;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Support\Events\SupportTicketAssigned;
use App\Modules\Support\Events\SupportTicketResolved;
use App\Modules\Support\Events\SupportTicketResponseAdded;
use App\Modules\Support\Listeners\LogSupportTicketActivity;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Policies\SupportTicketPolicy;
use App\Modules\Users\Events\UserActivated;
use App\Modules\Users\Events\UserBanned;
use App\Modules\Users\Events\UserDeleted;
use App\Modules\Users\Events\UserSuspended;
use App\Modules\Users\Events\UserUnbanned;
use App\Modules\Users\Events\UserUpdated;
use App\Modules\Users\Listeners\LogUserActivity;
use App\Modules\Users\Listeners\SendUserModerationMail;
use App\Shared\Services\BrevoApiTransport;
use App\Modules\Users\Policies\UserManagementPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
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
        // Brevo transactional email over their HTTPS API (port 443). Some
        // hosts (Render free tier included) block outbound SMTP ports, so
        // MAIL_MAILER=brevo-api + BREVO_API_KEY provides a working path.
        Mail::extend('brevo-api', function (array $config) {
            // config(), not env(): config is cached at boot on Render, and
            // env() calls outside config/ files return null after that.
            return new BrevoApiTransport(
                apiKey: (string) config('services.brevo.api_key'),
                timeoutSeconds: (int) config('services.brevo.timeout', 15),
            );
        });

        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid): bool {
            if (! $isValid || $accessToken->name === 'client-access') {
                return $isValid;
            }

            $timeout = (int) app(SettingsService::class)->value('system', 'session_timeout_minutes');

            return $timeout > 0
                && $accessToken->created_at->gt(now()->subMinutes($timeout));
        });

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
        Event::listen(AdministratorLoginFailed::class, LogAuthenticationActivity::class);
        Event::listen(PasswordChanged::class, LogAuthenticationActivity::class);
        Event::listen(NotificationSent::class, BroadcastClientNotification::class);

        // Administrator Management module events.
        Event::listen(AdministratorCreated::class, LogAdministratorActivity::class);
        Event::listen(AdministratorUpdated::class, LogAdministratorActivity::class);
        Event::listen(AdministratorStatusChanged::class, LogAdministratorActivity::class);
        Event::listen(AdministratorPasswordChanged::class, LogAdministratorActivity::class);
        Event::listen(RoleCreated::class, LogAdministratorActivity::class);
        Event::listen(RoleUpdated::class, LogAdministratorActivity::class);
        Event::listen(RoleDeleted::class, LogAdministratorActivity::class);
        Event::listen(RolePermissionsSynced::class, LogAdministratorActivity::class);

        // User Management module events.
        Event::listen(UserUpdated::class, LogUserActivity::class);
        Event::listen(UserSuspended::class, LogUserActivity::class);
        Event::listen(UserActivated::class, LogUserActivity::class);
        Event::listen(UserBanned::class, LogUserActivity::class);
        Event::listen(UserUnbanned::class, LogUserActivity::class);
        Event::listen(UserDeleted::class, LogUserActivity::class);

        // Ban / unban notifications (best-effort email delivery).
        Event::listen(UserBanned::class, SendUserModerationMail::class);
        Event::listen(UserUnbanned::class, SendUserModerationMail::class);

        // Service Category Management module events.
        Event::listen(ServiceCategoryCreated::class, LogServiceCategoryActivity::class);
        Event::listen(ServiceCategoryUpdated::class, LogServiceCategoryActivity::class);
        Event::listen(ServiceCategoryDeleted::class, LogServiceCategoryActivity::class);
        Event::listen(ServiceCategoryStatusChanged::class, LogServiceCategoryActivity::class);
        Event::listen(ServiceSubcategoryCreated::class, LogServiceCategoryActivity::class);
        Event::listen(ServiceSubcategoryUpdated::class, LogServiceCategoryActivity::class);
        Event::listen(ServiceSubcategoryDeleted::class, LogServiceCategoryActivity::class);

        // Administrator Management module policies.
        Gate::policy(User::class, AdministratorPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);

        // User Management — the User model policy slot belongs to the
        // Administrators module, so this module exposes a single named ability
        // backed by its own policy class.
        Gate::define('manage users', [UserManagementPolicy::class, 'manage']);
        Gate::define('view users', [UserManagementPolicy::class, 'view']);
        Gate::define('edit users', [UserManagementPolicy::class, 'edit']);
        Gate::define('delete users', [UserManagementPolicy::class, 'delete']);
        Gate::define('suspend users', [UserManagementPolicy::class, 'suspend']);
        Gate::define('activate users', [UserManagementPolicy::class, 'activate']);
        Gate::define('ban users', [UserManagementPolicy::class, 'ban']);

        // Service Category Management module policies.
        Gate::policy(ServiceCategory::class, ServiceCategoryPolicy::class);

        // Provider Management module events.
        Event::listen(ProviderVerificationApproved::class, LogProviderActivity::class);
        Event::listen(ProviderVerificationRejected::class, LogProviderActivity::class);
        Event::listen(ProviderAdditionalInfoRequested::class, LogProviderActivity::class);
        Event::listen(ProviderSuspended::class, LogProviderActivity::class);
        Event::listen(ProviderActivated::class, LogProviderActivity::class);
        Event::listen(ProviderVerificationRemoved::class, LogProviderActivity::class);
        Event::listen(ProviderRecognitionChanged::class, LogProviderRecognitionActivity::class);

        // Service Management module events.
        Event::listen(ServiceCreated::class, LogServiceActivity::class);
        Event::listen(ServiceUpdated::class, LogServiceActivity::class);
        Event::listen(ServiceApproved::class, LogServiceActivity::class);
        Event::listen(ServiceRejected::class, LogServiceActivity::class);
        Event::listen(ServiceHidden::class, LogServiceActivity::class);
        Event::listen(ServiceFeatured::class, LogServiceActivity::class);
        Event::listen(ServiceDeleted::class, LogServiceActivity::class);

        // Booking Management module events.
        Event::listen(BookingStatusChanged::class, LogBookingActivity::class);
        Event::listen(BookingCancelled::class, LogBookingActivity::class);
        Event::listen(BookingDisputeManaged::class, LogBookingActivity::class);

        // Booking Management module policies.
        Gate::policy(Booking::class, BookingPolicy::class);

        // Service Management module policies.
        Gate::policy(Service::class, ServicePolicy::class);

        // Reviews and Ratings Management module events.
        Event::listen(ReviewHidden::class, LogReviewActivity::class);
        Event::listen(ReviewRestored::class, LogReviewActivity::class);
        Event::listen(ReviewRemoved::class, LogReviewActivity::class);

        // Reviews and Ratings Management module policies.
        Gate::policy(Review::class, ReviewPolicy::class);

        // Reports and Moderation module events.
        Event::listen(ReportInvestigated::class, LogReportActivity::class);
        Event::listen(ReportNoteAdded::class, LogReportActivity::class);
        Event::listen(ReportResolved::class, LogReportActivity::class);
        Event::listen(ReportRejected::class, LogReportActivity::class);
        Event::listen(ReportActionTaken::class, LogReportActivity::class);

        // Reports and Moderation module policies.
        Gate::policy(Report::class, ReportPolicy::class);

        // Support Management module events and policy.
        Event::listen(SupportTicketAssigned::class, LogSupportTicketActivity::class);
        Event::listen(SupportTicketResponseAdded::class, LogSupportTicketActivity::class);
        Event::listen(SupportTicketResolved::class, LogSupportTicketActivity::class);
        Event::listen(SupportTicketResponseAdded::class, NotifyClientSupportTicket::class);
        Event::listen(SupportTicketResolved::class, NotifyClientSupportTicket::class);
        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);

        // Notifications and Announcements module policies.
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::define('view dashboard', [DashboardPolicy::class, 'viewAny']);

        // Reports and Analytics module gates (read-only aggregates).
        Gate::define('view analytics', [AnalyticsPolicy::class, 'viewAny']);
        Gate::define('export analytics', [AnalyticsPolicy::class, 'export']);

        // Provider Recognition module gates.
        Gate::define('view provider recognition', [ProviderRecognitionPolicy::class, 'view']);
        Gate::define('manage provider badges', [ProviderRecognitionPolicy::class, 'manageBadges']);
        Gate::define('assign provider badges', [ProviderRecognitionPolicy::class, 'assignBadges']);
        Gate::define('manage featured providers', [ProviderRecognitionPolicy::class, 'featured']);
        Gate::define('view top rated providers', [ProviderRecognitionPolicy::class, 'topRated']);

        // Security and Audit Log module gates.
        Gate::define('view audit logs', [AuditLogPolicy::class, 'view']);
        Gate::define('view login activity', [AuditLogPolicy::class, 'login']);
        Gate::define('monitor security events', [AuditLogPolicy::class, 'security']);

        // Provider Management module policies.
        Gate::policy(ProviderProfile::class, ProviderPolicy::class);

        // Provider Management — named gates for non-model policy actions.
        Gate::define('manage providers', [ProviderPolicy::class, 'manage']);
        Gate::define('view providers', [ProviderPolicy::class, 'view']);
        Gate::define('edit providers', [ProviderPolicy::class, 'update']);
        Gate::define('delete providers', [ProviderPolicy::class, 'delete']);
        Gate::define('suspend providers', [ProviderPolicy::class, 'suspend']);
        Gate::define('activate providers', [ProviderPolicy::class, 'activate']);
        Gate::define('verify providers', [ProviderPolicy::class, 'verify']);
        Gate::define('reject providers', [ProviderPolicy::class, 'reject']);
    }
}
