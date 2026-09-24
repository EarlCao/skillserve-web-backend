<?php

namespace App\Modules\IdentityVerification\Listeners;

use App\Modules\IdentityVerification\Events\IdentityVerificationApproved;
use App\Modules\IdentityVerification\Events\IdentityVerificationRejected;
use App\Modules\IdentityVerification\Events\IdentityVerificationSubmitted;

/**
 * Writes identity decisions to the Spatie activity log.
 *
 * Deciding who a person is, is a decision the platform must be able to
 * account for. The card number is deliberately never written here — the
 * activity log is widely readable, and last4 is enough to tie an entry to a
 * submission. Registered explicitly in AppServiceProvider.
 */
class LogIdentityVerificationActivity
{
    public function handle(
        IdentityVerificationSubmitted|IdentityVerificationApproved|IdentityVerificationRejected $event,
    ): void {
        [$properties, $description] = match (true) {
            $event instanceof IdentityVerificationSubmitted => [
                ['id_number_last4' => $event->verification->id_number_last4],
                'identity_verification_submitted',
            ],
            $event instanceof IdentityVerificationApproved => [
                ['id_number_last4' => $event->verification->id_number_last4, 'notes' => $event->notes],
                'identity_verification_approved',
            ],
            default => [
                ['id_number_last4' => $event->verification->id_number_last4, 'reason' => $event->reason],
                'identity_verification_rejected',
            ],
        };

        activity('identity_verifications')
            ->causedBy($event->actor)
            ->performedOn($event->verification)
            ->withProperties($properties)
            ->log($description);
    }
}
