<?php

namespace App\Modules\Providers\Actions;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Users\Actions\ActivateUserAction;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Activates a suspended service provider, and the account suspended with
 * them, so they can sign in again. A banned account is left banned.
 */
class ActivateProviderAction
{
    public function __construct(
        private readonly ActivateUserAction $activateUserAction,
    ) {}

    public function handle(ProviderProfile $profile, User $actor): ProviderProfile
    {
        if (! $profile->isSuspended()) {
            throw new ApiException(
                'This provider is not suspended.',
                422,
                errors: ['status' => ['The provider is not currently suspended.']],
            );
        }

        return DB::transaction(function () use ($profile, $actor) {
            $profile->update([
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ]);

            if ($profile->user?->isSuspended()) {
                $this->activateUserAction->handle($profile->user, $actor);
            }

            return $profile->fresh(['user']);
        });
    }
}
