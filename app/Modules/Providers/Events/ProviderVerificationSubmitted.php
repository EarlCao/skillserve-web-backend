<?php

namespace App\Modules\Providers\Events;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A provider submitted verification documents from the app — a new request,
 * or more documents answering an administrator's information request.
 */
class ProviderVerificationSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly ProviderProfile $providerProfile,
        public readonly User $actor,
        public readonly VerificationRequest $request,
        public readonly int $documentCount,
        public readonly bool $isResponse,
    ) {}
}
