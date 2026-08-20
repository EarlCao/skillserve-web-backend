<?php

namespace App\Modules\Providers\Actions;

use App\Models\User;
use App\Modules\Providers\Models\VerificationRequest;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Rejects a provider's verification request.
 */
class RejectVerificationAction
{
    public function handle(VerificationRequest $request, User $actor, string $reason): VerificationRequest
    {
        if ($request->status !== 'pending') {
            throw new ApiException(
                'This verification request cannot be rejected.',
                422,
                errors: ['status' => ['Only pending verification requests can be rejected.']],
            );
        }

        return DB::transaction(function () use ($request, $actor, $reason) {
            // Update the verification request
            $request->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
            ]);

            // Update the provider profile
            $profile = $request->providerProfile;
            $profile->update([
                'verification_status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            return $request->fresh(['providerProfile', 'reviewedBy']);
        });
    }
}
