<?php

namespace App\Modules\Providers\Actions;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Activates a suspended service provider.
 */
class ActivateProviderAction
{
    public function handle(ProviderProfile $profile, User $actor): ProviderProfile
    {
        if (! $profile->isSuspended()) {
            throw new ApiException(
                'This provider is not suspended.',
                422,
                errors: ['status' => ['The provider is not currently suspended.']],
            );
        }

        return DB::transaction(function () use ($profile) {
            $profile->update([
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ]);

            return $profile->fresh(['user']);
        });
    }
}
