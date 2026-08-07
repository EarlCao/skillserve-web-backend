<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Actions\ChangePasswordAction;
use App\Modules\Authentication\Events\PasswordChanged;
use App\Models\User;

/**
 * Handles password management for authenticated administrators.
 */
class PasswordService
{
    public function __construct(
        private readonly ChangePasswordAction $changePasswordAction,
    ) {
    }

    /**
     * Verify the current password, store the new one, and notify the rest of
     * the application (activity log, listeners, ...).
     *
     * @param  array{current_password: string, password: string}  $validated
     */
    public function changePassword(User $user, array $validated): void
    {
        $this->changePasswordAction->handle(
            $user,
            $validated['current_password'],
            $validated['password'],
        );

        event(new PasswordChanged(
            user: $user,
            ip: request()->ip(),
            userAgent: request()->userAgent(),
        ));
    }
}
