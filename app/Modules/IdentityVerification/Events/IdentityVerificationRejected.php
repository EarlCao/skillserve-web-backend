<?php

namespace App\Modules\IdentityVerification\Events;

use App\Models\User;
use App\Modules\IdentityVerification\Models\IdentityVerification;

/** Dispatched after an administrator refuses a National ID submission. */
class IdentityVerificationRejected
{
    public function __construct(
        public readonly IdentityVerification $verification,
        public readonly User $actor,
        public readonly string $reason,
    ) {}
}
