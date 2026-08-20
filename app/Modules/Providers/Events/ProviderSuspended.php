<?php

namespace App\Modules\Providers\Events;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Events\Dispatchable;

class ProviderSuspended
{
    use Dispatchable;

    public function __construct(
        public readonly ProviderProfile $providerProfile,
        public readonly User $actor,
        public readonly string $reason,
    ) {}
}
