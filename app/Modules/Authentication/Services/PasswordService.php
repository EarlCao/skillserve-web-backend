<?php

namespace App\Modules\Authentication\Services;

use App\Models\User;
use App\Modules\Authentication\Actions\ChangePasswordAction;
use App\Modules\Authentication\Events\PasswordChanged;

/**
 * Password management for administrators: changing it while signed in. A
 * forgotten password is reset by another administrator in Administrator
 * Management — there is no self-service reset by email.
 */
class PasswordService
{
    public function __construct(
        private readonly ChangePasswordAction $changePasswordAction,
    ) {}

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
            $user->currentAccessToken()?->id,
        );

        event(new PasswordChanged(
            user: $user,
            ip: request()->ip(),
            userAgent: request()->userAgent(),
        ));
    }
}
