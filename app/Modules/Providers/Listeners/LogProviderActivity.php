<?php

namespace App\Modules\Providers\Listeners;

use App\Modules\Providers\Events\ProviderActivated;
use App\Modules\Providers\Events\ProviderAdditionalInfoRequested;
use App\Modules\Providers\Events\ProviderSuspended;
use App\Modules\Providers\Events\ProviderVerificationApproved;
use App\Modules\Providers\Events\ProviderVerificationRejected;
use App\Modules\Providers\Events\ProviderVerificationRemoved;
use App\Modules\Providers\Events\ProviderVerificationSubmitted;

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
            $event instanceof ProviderVerificationSubmitted => $event->providerProfile,
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
            $event instanceof ProviderVerificationSubmitted => $event->isResponse
                ? 'provider_additional_info_submitted'
                : 'provider_verification_submitted',
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
            $event instanceof ProviderVerificationSubmitted => [
                'verification_request_id' => $event->request->id,
                'documents' => $event->documentCount,
            ],
            default => [],
        };

        activity('provider')
            ->causedBy($event->actor)
            ->performedOn($profile)
            ->event($description)
            ->withProperties($properties)
            ->log($description);
    }
}
