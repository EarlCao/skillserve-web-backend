<?php

namespace App\Modules\Providers\Actions;

use App\Models\User;
use App\Modules\Providers\Models\VerificationRequest;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Requests additional information from a provider for their verification.
 */
class RequestAdditionalInfoAction
{
    public function handle(VerificationRequest $request, User $actor, string $message): VerificationRequest
    {
        if ($request->status !== 'pending') {
            throw new ApiException(
                'Additional information can only be requested for pending verification requests.',
                422,
                errors: ['status' => ['Only pending verification requests can have additional information requested.']],
            );
        }

        return DB::transaction(function () use ($request, $actor, $message) {
            // Update the verification request
            $request->update([
                'status' => 'additional_info_required',
                'additional_info_request' => $message,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
            ]);

            // Update the provider profile
            $profile = $request->providerProfile;
            $profile->update([
                'verification_status' => 'additional_info_required',
            ]);

            return $request->fresh(['providerProfile', 'reviewedBy']);
        });
    }
}
