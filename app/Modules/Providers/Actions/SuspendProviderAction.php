<?php

namespace App\Modules\Providers\Actions;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Suspends a service provider, preventing them from offering services.
 */
class SuspendProviderAction
{
    public function handle(ProviderProfile $profile, User $actor, string $reason): ProviderProfile
    {
        if ($profile->isSuspended()) {
            throw new ApiException(
                'This provider is already suspended.',
                422,
                errors: ['status' => ['The provider is already suspended.']],
            );
        }

        return DB::transaction(function () use ($profile, $actor, $reason) {
            $profile->update([
                'suspended_at' => now(),
                'suspended_by' => $actor->id,
                'suspension_reason' => $reason,
            ]);

            return $profile->fresh(['user', 'suspendedBy']);
        });
    }
}
