<?php

namespace App\Modules\Providers\Actions;

use App\Models\User;
use App\Modules\Providers\Models\VerificationRequest;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Approves a provider's verification request and updates the provider's status.
 */
class ApproveVerificationAction
{
    public function handle(VerificationRequest $request, User $actor, ?string $notes = null): VerificationRequest
    {
        if ($request->status !== 'pending') {
            throw new ApiException(
                'This verification request cannot be approved.',
                422,
                errors: ['status' => ['Only pending verification requests can be approved.']],
            );
        }

        return DB::transaction(function () use ($request, $actor, $notes) {
            // Update the verification request
            $request->update([
                'status' => 'approved',
                'admin_notes' => $notes,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
            ]);

            // Update the provider profile
            $profile = $request->providerProfile;
            $profile->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'rejection_reason' => null,
            ]);

            // Update the user's verification status
            $profile->user->update([
                'email_verified_at' => $profile->user->email_verified_at ?? now(),
            ]);

            return $request->fresh(['providerProfile', 'reviewedBy']);
        });
    }
}
