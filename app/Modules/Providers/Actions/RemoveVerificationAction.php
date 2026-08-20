<?php

namespace App\Modules\Providers\Actions;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Removes the verified status of a provider.
 */
class RemoveVerificationAction
{
    public function handle(ProviderProfile $profile, User $actor): ProviderProfile
    {
        if (! $profile->isVerified()) {
            throw new ApiException(
                'This provider is not verified.',
                422,
                errors: ['status' => ['The provider is not currently verified.']],
            );
        }

        return DB::transaction(function () use ($profile) {
            $profile->update([
                'verification_status' => 'unverified',
                'verified_at' => null,
                'verified_by' => null,
                'rejection_reason' => null,
            ]);

            return $profile->fresh(['user']);
        });
    }
}
