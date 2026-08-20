<?php

namespace App\Modules\Services\Listeners;

use App\Models\User;
use App\Modules\Services\Events\ServiceApproved;
use App\Modules\Services\Events\ServiceCreated;
use App\Modules\Services\Events\ServiceDeleted;
use App\Modules\Services\Events\ServiceFeatured;
use App\Modules\Services\Events\ServiceHidden;
use App\Modules\Services\Events\ServiceRejected;
use App\Modules\Services\Events\ServiceUpdated;

/**
 * Persists Service Management events into the Spatie activity log.
 *
 * Registered explicitly in AppServiceProvider (module listeners live outside
 * app/Listeners, so auto-discovery does not apply).
 */
class LogServiceActivity
{
    /**
     * Handle the service-module events.
     */
    public function handle(
        ServiceCreated|ServiceUpdated|ServiceApproved|ServiceRejected|
        ServiceHidden|ServiceFeatured|ServiceDeleted $event,
    ): void {
        match (true) {
            $event instanceof ServiceCreated => $this->log(
                'services', $event->actor, $event->service,
                ['created' => $event->data], 'service_created',
            ),
            $event instanceof ServiceUpdated => $this->log(
                'services', $event->actor, $event->service,
                ['before' => $event->before, 'after' => $event->after], 'service_updated',
            ),
            $event instanceof ServiceApproved => $this->log(
                'services', $event->actor, $event->service,
                ['notes' => $event->notes], 'service_approved',
            ),
            $event instanceof ServiceRejected => $this->log(
                'services', $event->actor, $event->service,
                ['reason' => $event->reason], 'service_rejected',
            ),
            $event instanceof ServiceHidden => $this->log(
                'services', $event->actor, $event->service,
                ['is_hidden' => $event->isHidden], 'service_hidden',
            ),
            $event instanceof ServiceFeatured => $this->log(
                'services', $event->actor, $event->service,
                ['is_featured' => $event->isFeatured], 'service_featured',
            ),
            default => $this->log(
                'services', $event->actor, $event->service,
                ['title' => $event->service->title], 'service_deleted',
            ),
        };
    }

    /**
     * Write a single activity-log entry.
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(
        string $logName,
        User $actor,
        $subject,
        array $properties,
        string $description,
    ): void {
        activity($logName)
            ->causedBy($actor)
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }
}
