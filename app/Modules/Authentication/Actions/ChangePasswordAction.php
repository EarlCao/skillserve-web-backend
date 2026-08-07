<?php

namespace App\Modules\Authentication\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Hash;

/**
 * Single unit of work: verify the current password and store a new one.
 */
final class ChangePasswordAction extends BaseAction
{
    /**
     * @throws ApiException when the current password does not match.
     */
    public function handle(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new ApiException(
                'The current password is incorrect.',
                422,
                errors: ['current_password' => ['The current password is incorrect.']],
            );
        }

        // The model's "hashed" cast takes care of secure hashing.
        $user->password = $newPassword;
        $user->save();
    }
}
