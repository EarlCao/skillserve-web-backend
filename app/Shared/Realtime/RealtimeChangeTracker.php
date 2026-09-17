<?php

namespace App\Shared\Realtime;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\DataManagement\Models\DataArchive;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
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
 * and broadcasts them once at the end as a single AdminDataChanged event.
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

    /** @var array<string, true> */
    private array $pending = [];

    public function recordModel(Model $model): void
    {
        $resource = self::MODEL_RESOURCES[$model::class] ?? null;

        if ($resource === null) {
            return;
        }

        // Writes made while serving a read (e.g. view logging) would make
        // every dashboard refetch, write again and loop, so skip them.
        if (! app()->runningInConsole() && request()->isMethodSafe()) {
            return;
        }

        $this->record($resource);
    }

    public function record(string ...$resources): void
    {
        foreach ($resources as $resource) {
            $this->pending[$resource] = true;
        }
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $resources = array_keys($this->pending);
        $this->pending = [];

        // Live updates are best-effort: a missing WebSocket server must never
        // fail the write that already succeeded. Dashboards fall back to polling.
        try {
            event(new AdminDataChanged($resources));
        } catch (Throwable $exception) {
            Log::warning('Realtime admin update could not be broadcast.', [
                'resources' => $resources,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
