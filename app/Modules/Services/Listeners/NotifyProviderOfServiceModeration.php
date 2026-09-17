<?php

namespace App\Modules\Services\Listeners;

use App\Models\User;
use App\Modules\Services\Events\ServiceApproved;
use App\Modules\Services\Events\ServiceDeleted;
use App\Modules\Services\Events\ServiceFeatured;
use App\Modules\Services\Events\ServiceHidden;
use App\Modules\Services\Events\ServiceRejected;
use App\Modules\Services\Events\ServiceUpdated;
use App\Modules\Services\Notifications\ServiceModerationNotification;

/**
 * Notify the owning provider whenever an administrator approves, rejects,
 * edits, hides, features or removes their service. Changes the provider
 * makes to their own service do not notify them.
 */
class NotifyProviderOfServiceModeration
{
    private const FIELD_LABELS = [
        'title' => 'title',
        'description' => 'description',
        'category_id' => 'category',
        'status' => 'status',
        'is_featured' => 'featured flag',
        'is_hidden' => 'visibility',
    ];

    public function handle(
        ServiceUpdated|ServiceApproved|ServiceRejected|ServiceHidden|ServiceFeatured|ServiceDeleted $event,
    ): void {
        $service = $event->service;
        // Load the full account: services often eager-load the provider's user
        // with only id/name, which would hide role_id (needed to push the
        // notification to the mobile app in realtime).
        $providerUser = User::query()->find($service->provider?->user_id);

        if (! $providerUser || $providerUser->is($event->actor)) {
            return;
        }

        $name = "\u{201C}{$service->title}\u{201D}";

        $notification = match (true) {
            $event instanceof ServiceApproved => new ServiceModerationNotification(
                $service, 'approved', 'Service approved',
                "Your service {$name} was approved and is now visible to customers."
                    .($event->notes ? " Note: {$event->notes}" : ''),
                $event->notes,
            ),
            $event instanceof ServiceRejected => new ServiceModerationNotification(
                $service, 'rejected', 'Service rejected',
                "Your service {$name} was rejected. Reason: {$event->reason}",
                $event->reason,
            ),
            $event instanceof ServiceHidden => $event->isHidden
                ? new ServiceModerationNotification($service, 'hidden', 'Service hidden', "An administrator hid your service {$name} from customers.")
                : new ServiceModerationNotification($service, 'unhidden', 'Service visible again', "Your service {$name} is visible to customers again."),
            $event instanceof ServiceFeatured => $event->isFeatured
                ? new ServiceModerationNotification($service, 'featured', 'Service featured', "Your service {$name} is now featured.")
                : new ServiceModerationNotification($service, 'unfeatured', 'Service no longer featured', "Your service {$name} is no longer featured."),
            $event instanceof ServiceDeleted => new ServiceModerationNotification(
                $service, 'deleted', 'Service removed',
                "An administrator removed your service {$name}.",
            ),
            default => $this->updatedNotification($event, $name),
        };

        if ($notification) {
            $providerUser->notify($notification);
        }
    }

    private function updatedNotification(ServiceUpdated $event, string $name): ?ServiceModerationNotification
    {
        $changed = array_keys(array_diff_assoc(
            array_map(fn ($value) => json_encode($value), $event->after),
            array_map(fn ($value) => json_encode($value), $event->before),
        ));
        $labels = array_values(array_unique(array_filter(array_map(
            fn (string $field) => self::FIELD_LABELS[$field] ?? null,
            $changed,
        ))));

        if ($labels === []) {
            return null;
        }

        return new ServiceModerationNotification(
            $event->service, 'updated', 'Service updated by an administrator',
            'An administrator updated the '.implode(', ', $labels)." of your service {$name}.",
        );
    }
}
