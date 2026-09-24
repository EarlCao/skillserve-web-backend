<?php

namespace App\Modules\IdentityVerification\Events;

use App\Models\User;
use App\Modules\IdentityVerification\Models\IdentityVerification;

/** Dispatched after an account holder submits their National ID for review. */
class IdentityVerificationSubmitted
{
    public function __construct(
        public readonly IdentityVerification $verification,
        public readonly User $actor,
    ) {}
}
