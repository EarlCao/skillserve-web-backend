<?php

namespace App\Modules\IdentityVerification\Listeners;

use App\Modules\IdentityVerification\Events\IdentityVerificationApproved;
use App\Modules\IdentityVerification\Events\IdentityVerificationRejected;
use App\Modules\IdentityVerification\Notifications\IdentityDecisionNotification;

/** An administrator's decision on a National ID reaches its holder. */
class NotifyHolderOfIdentityDecision
{
    public function handle(IdentityVerificationApproved|IdentityVerificationRejected $event): void
    {
        $event->verification->user?->notify($event instanceof IdentityVerificationApproved
            ? new IdentityDecisionNotification(
                'approved', 'ID verified',
                'Your National ID was approved. Your account now shows it as verified.',
            )
            : new IdentityDecisionNotification(
                'rejected', 'ID not approved',
                "Your National ID was not approved. Reason: {$event->reason} You can submit it again.",
                $event->reason,
            ));
    }
}
