<?php

namespace App\Shared\Realtime;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionRemittance;
use App\Modules\DataManagement\Models\DataArchive;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\Providers\Models\ProviderAvailability;
use App\Modules\Providers\Models\ProviderPortfolioItem;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationDocument;
use App\Modules\Providers\Models\VerificationRequest;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Reviews\Models\Review;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Modules\Services\Models\Service;
use App\Modules\Settings\Models\Setting;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Collects which resources changed during a request, command or queued job
 * and broadcasts them once at the end: a single AdminDataChanged event for
 * the admin dashboards, and a single MarketplaceChanged event for the mobile
 * app when the change touched the public catalog.
 */
class RealtimeChangeTracker
{
    /**
     * Only models admins see in the dashboard. Anything else (API tokens,
     * refresh tokens, media) changes on routine requests and is ignored.
     *
     * @var array<class-string<Model>, string>
     */
    private const MODEL_RESOURCES = [
        User::class => 'users',
        ProviderProfile::class => 'providers',
        VerificationRequest::class => 'providers',
        VerificationDocument::class => 'providers',
        ProviderBadge::class => 'provider-recognition',
        ServiceCategory::class => 'service-categories',
        ServiceSubcategory::class => 'service-categories',
        Service::class => 'services',
        Booking::class => 'bookings',
        CommissionRemittance::class => 'commission-remittances',
        Message::class => 'messages',
        Review::class => 'reviews',
        Report::class => 'reports',
        SupportTicket::class => 'support-tickets',
        SupportTicketMessage::class => 'support-tickets',
        Announcement::class => 'notifications',
        Setting::class => 'settings',
        DataArchive::class => 'data-management',
        Activity::class => 'audit',
        Role::class => 'roles',
        Permission::class => 'roles',
    ];

    /**
     * Models whose changes the customer-facing catalog shows: listings, the
     * categories they sit in, and everything on a provider's public profile
     * (details, rating via reviews, badges, portfolio and hours).
     *
     * @var array<class-string<Model>, string>
     */
    private const MARKETPLACE_RESOURCES = [
        Service::class => 'services',
        ServiceCategory::class => 'categories',
        ServiceSubcategory::class => 'categories',
        ProviderProfile::class => 'providers',
        ProviderPortfolioItem::class => 'providers',
        ProviderAvailability::class => 'providers',
        ProviderBadge::class => 'providers',
        Review::class => 'reviews',
        // Marketplace switches such as "Enable featured services".
        Setting::class => 'settings',
    ];

    /**
     * The account fields the catalog shows or filters on. A sign-in or a token
     * refresh also writes the users table, and must not make every open app
     * refetch the marketplace.
     */
    private const MARKETPLACE_USER_FIELDS = [
        'name', 'first_name', 'last_name', 'profile_photo_path', 'status',
        'suspended_at', 'banned_at', 'banned_until', 'deleted_at',
    ];

    /** @var array<string, true> */
    private array $pending = [];

    /** @var array<string, true> */
    private array $pendingMarketplace = [];

    public function recordModel(Model $model): void
    {
        $resource = self::MODEL_RESOURCES[$model::class] ?? null;
        $marketplaceResource = $this->marketplaceResource($model);

        if ($resource === null && $marketplaceResource === null) {
            return;
        }

        // Writes made while serving a read (e.g. view logging) would make
        // every dashboard refetch, write again and loop, so skip them.
        if (! app()->runningInConsole() && request()->isMethodSafe()) {
            return;
        }

        if ($resource !== null) {
            $this->record($resource);
        }

        if ($marketplaceResource !== null) {
            $this->pendingMarketplace[$marketplaceResource] = true;
        }
    }

    /**
     * The catalog resource [model] belongs to, or null when the change is
     * invisible to customers. An account change counts only for a provider,
     * and only when it touches a field the catalog shows — their name, photo
     * or standing. Customers and staff never appear in the catalog.
     */
    private function marketplaceResource(Model $model): ?string
    {
        if ($model instanceof User) {
            if ($model->user_type !== 'provider') {
                return null;
            }

            $removed = ! $model->exists || $model->trashed();

            return $removed || $model->wasChanged(self::MARKETPLACE_USER_FIELDS) ? 'providers' : null;
        }

        return self::MARKETPLACE_RESOURCES[$model::class] ?? null;
    }

    public function record(string ...$resources): void
    {
        foreach ($resources as $resource) {
            $this->pending[$resource] = true;
        }
    }

    public function flush(): void
    {
        if ($this->pending !== []) {
            $resources = array_keys($this->pending);
            $this->pending = [];
            $this->broadcast(new AdminDataChanged($resources), 'admin', $resources);
        }

        if ($this->pendingMarketplace !== []) {
            $resources = array_keys($this->pendingMarketplace);
            $this->pendingMarketplace = [];
            $this->broadcast(new MarketplaceChanged($resources), 'marketplace', $resources);
        }
    }

    /**
     * Live updates are best-effort: a missing WebSocket server must never
     * fail the write that already succeeded. Dashboards and the app fall back
     * to polling or pull-to-refresh.
     *
     * @param  array<int, string>  $resources
     */
    private function broadcast(object $event, string $audience, array $resources): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            Log::warning("Realtime {$audience} update could not be broadcast.", [
                'resources' => $resources,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
