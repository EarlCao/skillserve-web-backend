<?php

namespace App\Modules\Providers\Listeners;

use App\Models\User;
use App\Modules\Providers\Events\ProviderActivated;
use App\Modules\Providers\Events\ProviderAdditionalInfoRequested;
use App\Modules\Providers\Events\ProviderSuspended;
use App\Modules\Providers\Events\ProviderVerificationApproved;
use App\Modules\Providers\Events\ProviderVerificationRejected;
use App\Modules\Providers\Events\ProviderVerificationRemoved;
use App\Modules\Providers\Notifications\ProviderAccountNotification;

/**
 * Verification decisions and provider suspensions reach the provider, with
 * the administrator's reason or request.
 */
class NotifyProviderOfAccountDecision
{
    public function handle(
        ProviderVerificationApproved|ProviderVerificationRejected|ProviderAdditionalInfoRequested|ProviderVerificationRemoved|ProviderSuspended|ProviderActivated $event,
    ): void {
        // The full account, for realtime delivery.
        $user = User::query()->find($event->providerProfile->user_id);

        if (! $user) {
            return;
        }

        $user->notify(match (true) {
            $event instanceof ProviderVerificationApproved => new ProviderAccountNotification(
                'provider_verification', 'approved', "You're verified",
                'Your documents were approved. Clients now see your verified badge, and you can list services.'
                    .($event->notes ? " Note: {$event->notes}" : ''),
                $event->notes,
            ),
            $event instanceof ProviderVerificationRejected => new ProviderAccountNotification(
                'provider_verification', 'rejected', 'Verification not approved',
                "Your verification was not approved. Reason: {$event->reason} You can upload new documents.",
                $event->reason,
            ),
            $event instanceof ProviderAdditionalInfoRequested => new ProviderAccountNotification(
                'provider_verification', 'info_requested', 'More information needed',
                "The reviewer asked for more: {$event->message}",
                $event->message,
            ),
            $event instanceof ProviderVerificationRemoved => new ProviderAccountNotification(
                'provider_verification', 'removed', 'Verification removed',
                'An administrator removed your verified status. Submit your documents again to be re-verified.',
            ),
            $event instanceof ProviderSuspended => new ProviderAccountNotification(
                'provider_status', 'suspended', 'Provider account suspended',
                "Your provider account was suspended, so clients cannot find or book you. Reason: {$event->reason}",
                $event->reason,
            ),
            default => new ProviderAccountNotification(
                'provider_status', 'activated', 'Provider account active again',
                'Your provider account was reactivated. Clients can find and book you again.',
            ),
        });
    }
}
