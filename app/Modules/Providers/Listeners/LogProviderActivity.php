<?php

namespace App\Modules\Providers\Listeners;

use App\Modules\Providers\Events\ProviderActivated;
use App\Modules\Providers\Events\ProviderAdditionalInfoRequested;
use App\Modules\Providers\Events\ProviderSuspended;
use App\Modules\Providers\Events\ProviderVerificationApproved;
use App\Modules\Providers\Events\ProviderVerificationRejected;
use App\Modules\Providers\Events\ProviderVerificationRemoved;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Activitylog\LogOptions;

/**
 * Logs provider management activity to the activity_log table.
 *
 * Registered explicitly in AppServiceProvider (module listeners live outside
 * app/Listeners).
 */
class LogProviderActivity
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        $profile = match (true) {
            $event instanceof ProviderVerificationApproved => $event->providerProfile,
            $event instanceof ProviderVerificationRejected => $event->providerProfile,
            $event instanceof ProviderAdditionalInfoRequested => $event->providerProfile,
            $event instanceof ProviderSuspended => $event->providerProfile,
            $event instanceof ProviderActivated => $event->providerProfile,
            $event instanceof ProviderVerificationRemoved => $event->providerProfile,
            default => null,
        };

        if (! $profile) {
            return;
        }

        $description = match (true) {
            $event instanceof ProviderVerificationApproved => 'provider_verification_approved',
            $event instanceof ProviderVerificationRejected => 'provider_verification_rejected',
            $event instanceof ProviderAdditionalInfoRequested => 'provider_additional_info_requested',
            $event instanceof ProviderSuspended => 'provider_suspended',
            $event instanceof ProviderActivated => 'provider_activated',
            $event instanceof ProviderVerificationRemoved => 'provider_verification_removed',
            default => 'provider_action',
        };

        $properties = match (true) {
            $event instanceof ProviderVerificationApproved => [
                'notes' => $event->notes,
            ],
            $event instanceof ProviderVerificationRejected => [
                'reason' => $event->reason,
            ],
            $event instanceof ProviderAdditionalInfoRequested => [
                'message' => $event->message,
            ],
            $event instanceof ProviderSuspended => [
                'reason' => $event->reason,
            ],
            default => [],
        };

        Activity::tap(function ($activity) use ($event) {
            $activity->causer = $event->actor;
        })->log(
            LogOptions::defaults()
                ->useLogName('provider')
                ->subject($profile)
                ->event($description)
                ->withProperties($properties)
        );
    }
}
