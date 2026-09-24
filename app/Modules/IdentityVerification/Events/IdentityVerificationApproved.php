<?php

namespace App\Modules\IdentityVerification\Events;

use App\Models\User;
use App\Modules\IdentityVerification\Models\IdentityVerification;

/** Dispatched after an administrator confirms an account holder's National ID. */
class IdentityVerificationApproved
{
    public function __construct(
        public readonly IdentityVerification $verification,
        public readonly User $actor,
        public readonly ?string $notes = null,
    ) {}
}
