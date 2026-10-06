<?php

namespace App\Modules\Providers\Listeners;

use App\Modules\IdentityVerification\Events\IdentityVerificationApproved;
use App\Modules\Providers\Services\ProviderService;

/**
 * Approving a provider's National ID in Identity Verification verifies them
 * in Provider Management too, without a second approval there.
 */
class VerifyProviderOnIdentityApproval
{
    public function __construct(private readonly ProviderService $providers) {}

    public function handle(IdentityVerificationApproved $event): void
    {
        $user = $event->verification->user;

        if ($user !== null) {
            $this->providers->verifyFromIdentity($user, $event->actor);
        }
    }
}
