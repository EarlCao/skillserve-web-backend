<?php

namespace App\Modules\Providers\Actions;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Users\Actions\SuspendUserAction;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Suspends a service provider: they leave the marketplace, and their account
 * is suspended too, so they are signed out and cannot sign in until an
 * administrator reactivates them. A banned account stays banned.
 */
class SuspendProviderAction
{
    public function __construct(
        private readonly SuspendUserAction $suspendUserAction,
    ) {}

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

            if ($profile->user?->isActive()) {
                $this->suspendUserAction->handle($profile->user, $actor, $reason);
            }

            return $profile->fresh(['user', 'suspendedBy']);
        });
    }
}
